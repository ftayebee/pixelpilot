<?php

namespace Tests\Feature\Credits;

use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\InsufficientCredits;
use App\Services\Generations\GenerationPoller;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PixelforgeFixtures as F;
use Tests\Support\SendsPixelforgeWebhooks;
use Tests\TestCase;

class CreditsTest extends TestCase
{
    use RefreshDatabase;
    use SendsPixelforgeWebhooks;

    // high, 1536x1024, 45 chars, 1 reference → charge range 0.34775 … 0.45715
    private const MAX = '0.457150000000';

    private const ACTUAL = '0.381887500000';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Http::fake([
            F::BASE.'/pricing' => Http::response(F::pricing()),
            F::BASE.'/generations' => Http::response(F::generation('queued'), 202),
        ]);
    }

    private function balance(User $user): string
    {
        return (string) $user->fresh()->credit_balance;
    }

    private function generate(User $user, string $prompt = 'A lighthouse on a cliff at dawn, oil painting'): TestResponse
    {
        return $this->actingAs($user)->from('/generate')->post('/generations', [
            'prompt' => $prompt,
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'client_request_id' => (string) Str::uuid(),
        ]);
    }

    private function assertLedgerMatchesBalance(User $user): void
    {
        $sum = DB::table('credit_transactions')->where('user_id', $user->id)->sum('amount');
        $this->assertSame(0, bccomp((string) $sum, $this->balance($user), 12), 'Balance must equal the sum of the ledger');
    }

    public function test_fake_purchase_adds_credits_once_per_request(): void
    {
        $user = User::factory()->create();
        $request = (string) Str::uuid();

        $this->actingAs($user)->post('/credits/purchase', ['amount' => '10', 'purchase_request_id' => $request])->assertSessionHas('success');
        $this->actingAs($user)->post('/credits/purchase', ['amount' => '10', 'purchase_request_id' => $request]);

        $this->assertSame('10.000000000000', $this->balance($user));
        $purchase = CreditTransaction::sole();
        $this->assertSame('purchase', $purchase->type);
        $this->assertSame($request, $purchase->reference);
        $this->assertStringContainsString('simulated', $purchase->description);
    }

    public function test_purchase_validation_and_access(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits/purchase', ['amount' => '1000000', 'purchase_request_id' => (string) Str::uuid()])->assertSessionHasErrors('amount');
        $this->actingAs($user)->post('/credits/purchase', ['amount' => '-5', 'purchase_request_id' => (string) Str::uuid()])->assertSessionHasErrors('amount');
        $this->actingAs($user)->patch('/settings/profile', ['name' => 'x', 'email' => $user->email, 'credit_balance' => '999']);
        $this->assertSame('0.000000000000', $this->balance($user));

        $theirs = User::factory()->withCredits('5')->create();
        $reference = CreditTransaction::where('user_id', $theirs->id)->value('reference');
        $this->actingAs($user)->post('/credits/purchase', ['amount' => '5', 'purchase_request_id' => $reference])->assertStatus(422);
    }

    public function test_generating_reserves_the_maximum_estimate(): void
    {
        $user = User::factory()->withCredits('1')->create();

        $this->generate($user)->assertRedirect();

        $generation = Generation::sole();
        $this->assertSame(self::MAX, $generation->reserved_amount);
        $this->assertSame('0.542850000000', $this->balance($user)); // 1 − 0.45715
        $this->assertSame(self::MAX, app(CreditLedger::class)->reservedFor($user));
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_generation_is_blocked_when_the_user_cannot_afford_the_maximum(): void
    {
        $user = User::factory()->withCredits('0.40')->create(); // covers the minimum, not the maximum

        $this->generate($user)->assertRedirect('/generate')->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::count());
        $this->assertSame('0.400000000000', $this->balance($user));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_success_settles_at_the_actual_price(): void
    {
        $user = User::factory()->withCredits('1')->create();
        $this->generate($user);

        $this->sendSignedEvent(F::event('generation.succeeded', F::generation('succeeded'), 'evt_ok'));

        $this->assertSame('0.618112500000', $this->balance($user)); // 1 − 0.3818875
        $this->assertSame('0.000000000000', app(CreditLedger::class)->reservedFor($user));
        $this->assertSame(['purchase', 'reservation', 'release', 'charge'], CreditTransaction::orderBy('id')->pluck('type')->all());
        $this->assertSame('-'.self::ACTUAL, CreditTransaction::where('type', 'charge')->value('amount'));
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_duplicate_and_out_of_order_events_never_debit_twice(): void
    {
        $user = User::factory()->withCredits('1')->create();
        $this->generate($user);

        foreach (['evt_a' => 'succeeded', 'evt_b' => 'processing', 'evt_c' => 'failed'] as $id => $status) {
            $event = F::event("generation.{$status}", F::generation($status), $id);
            $this->sendSignedEvent($event);
            $this->sendSignedEvent($event, attempt: 2);
        }

        $this->assertSame('0.618112500000', $this->balance($user));
        $this->assertSame(1, CreditTransaction::where('type', 'charge')->count());
        $this->assertSame(1, CreditTransaction::where('type', 'release')->count());
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_failed_generation_returns_the_reservation(): void
    {
        $user = User::factory()->withCredits('1')->create();
        $this->generate($user);

        $this->sendSignedEvent(F::event('generation.failed', F::generation('failed'), 'evt_fail'));

        $this->assertSame('1.000000000000', $this->balance($user));
        $this->assertSame(0, CreditTransaction::where('type', 'charge')->count());
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_rejected_submission_returns_the_reservation(): void
    {
        $user = User::factory()->withCredits('1')->create();

        // Replace the default 202 fake from setUp with a rejection.
        Http::swap(new Factory);
        Http::fake([
            F::BASE.'/pricing' => Http::response(F::pricing()),
            F::BASE.'/generations' => Http::response(F::error('content_policy_violation', 'invalid_request_error', 'Rejected'), 400),
        ]);

        $this->generate($user, 'something [blocked]')->assertSessionHasErrors('prompt');

        $this->assertSame(GenerationStatus::Failed, Generation::sole()->status);
        $this->assertSame('1.000000000000', $this->balance($user));
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_abandoned_generation_returns_the_reservation(): void
    {
        $user = User::factory()->withCredits('1')->create();
        $this->generate($user);
        $generation = Generation::sole();
        $generation->forceFill(['created_at' => now()->subHours(49)])->save();

        app(GenerationPoller::class)->poll($generation->fresh());

        $this->assertSame('1.000000000000', $this->balance($user));
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_reservations_cannot_overspend_the_balance(): void
    {
        $user = User::factory()->withCredits('0.70')->create(); // enough for one maximum, not two

        $this->generate($user)->assertSessionHasNoErrors();
        $this->generate($user)->assertSessionHasErrors('credits');

        $this->assertSame(1, Generation::count());
        $this->assertSame('0.242850000000', $this->balance($user));
    }

    public function test_shortfall_policy_records_the_true_charge_and_flags_it(): void
    {
        // A generation whose reservation is smaller than what Pixelforge ends up billing.
        $user = User::factory()->withCredits('0.10')->create();
        $generation = Generation::factory()->for($user)->create(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
        app(CreditLedger::class)->reserve($generation, '0.10');

        $this->sendSignedEvent(F::event('generation.succeeded', F::generation('succeeded'), 'evt_short'));

        $charge = CreditTransaction::where('type', 'charge')->sole();
        $this->assertSame('-'.self::ACTUAL, $charge->amount);
        $this->assertSame('0.281887500000', $charge->shortfall);
        $this->assertSame('-0.281887500000', $this->balance($user));
        $this->assertLedgerMatchesBalance($user);

        // And the user can't start anything else until they top up.
        $this->generate($user)->assertSessionHasErrors('credits');
    }

    public function test_ledger_is_append_only(): void
    {
        $user = User::factory()->withCredits('5')->create();

        $this->expectException(QueryException::class);
        DB::table('credit_transactions')->where('user_id', $user->id)->update(['amount' => '500']);
    }

    public function test_ledger_rejects_wrongly_signed_entries(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('credit_transactions')->insert([
            'reference' => (string) Str::uuid(),
            'user_id' => $user->id,
            'type' => 'purchase',
            'amount' => '-5',
            'balance_after' => '-5',
            'description' => 'x',
        ]);
    }

    public function test_reserve_throws_with_a_helpful_message(): void
    {
        $user = User::factory()->withCredits('0.10')->create();
        $generation = Generation::factory()->for($user)->create();

        try {
            app(CreditLedger::class)->reserve($generation, '0.457150000000');
            $this->fail('Expected InsufficientCredits');
        } catch (InsufficientCredits $e) {
            $this->assertStringContainsString('$0.46', $e->getMessage());
            $this->assertStringContainsString('$0.10', $e->getMessage());
        }
    }

    public function test_credits_page_shows_balance_reserved_and_history(): void
    {
        $user = User::factory()->withCredits('1')->create();
        $this->generate($user);

        $this->actingAs($user->refresh())->get('/credits')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('credits/Index')
                ->where('balance', '0.542850000000')
                ->where('reserved', self::MAX)
                ->where('auth.user.credit_balance', '0.542850000000')
                ->has('transactions.data', 2)
                ->where('transactions.data.0.type', 'reservation')
                ->has('purchaseRequestId'));
    }
}
