<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\GenerationCharge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PixelforgeFixtures as F;
use Tests\Support\SendsPixelforgeWebhooks;
use Tests\TestCase;

/**
 * The whole path a user's image takes, through real HTTP requests:
 * form → Pixelforge (faked) → webhooks → charge → history and dashboard.
 */
class GenerationLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use SendsPixelforgeWebhooks;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->user = User::factory()->create();
        // Each create returns a new Pixelforge id; the first matches the webhook fixtures.
        $creates = 0;
        Http::fake([
            F::BASE.'/pricing' => Http::response(F::pricing()),
            F::BASE.'/generations' => function () use (&$creates) {
                $overrides = $creates++ === 0 ? [] : ['id' => 'gen_RETRY'.$creates];

                return Http::response(F::generation('queued', $overrides), 202);
            },
        ]);
    }

    private function submit(): Generation
    {
        $this->actingAs($this->user)->post('/generations', [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'client_request_id' => (string) Str::uuid(),
        ])->assertRedirect();

        return Generation::latest('id')->firstOrFail();
    }

    public function test_successful_generation_end_to_end(): void
    {
        $generation = $this->submit();
        $this->assertSame(GenerationStatus::Queued, $generation->status);

        $this->sendSignedEvent(F::event('generation.processing', F::generation('processing'), 'evt_p'))->assertNoContent();
        $this->assertSame(GenerationStatus::Processing, $generation->fresh()->status);

        $this->sendSignedEvent(F::event('generation.succeeded', F::generation('succeeded', [
            'image_url_expires_at' => now()->addDay()->toIso8601ZuluString(),
        ]), 'evt_s'))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame('0.381887500000', $generation->actual_charge);
        // The actual charge falls inside the range we showed before submitting.
        $this->assertGreaterThanOrEqual(0, bccomp($generation->actual_charge, $generation->estimated_charge_min, 12));
        $this->assertLessThanOrEqual(0, bccomp($generation->actual_charge, $generation->estimated_charge_max, 12));

        $this->actingAs($this->user)->get(route('generations.show', $generation))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generation.status', 'succeeded')
                ->where('generation.actual_charge', '0.381887500000')
                ->where('generation.actual_cost', '0.305510000000')
                ->where('generation.usage.reasoning_tokens', 3184)
                ->where('generation.image_url', fn ($url) => str_starts_with($url, 'https://pixelforge.promptedit.dev/images/')));

        $this->actingAs($this->user)->get('/generations')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('generations.data.0.actual_charge', '0.381887500000'));

        $this->actingAs($this->user)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.totals.succeeded', 1)
                ->where('stats.totals.spent', '0.381887500000')
                ->where('stats.totals.success_rate', 100));
    }

    public function test_accepted_generation_that_fails_later_is_free(): void
    {
        $generation = $this->submit();

        $this->sendSignedEvent(F::event('generation.processing', F::generation('processing'), 'evt_p'));
        $this->sendSignedEvent(F::event('generation.failed', F::generation('failed'), 'evt_f'));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('0.000000000000', $generation->actual_charge);
        $this->assertSame(0, GenerationCharge::count());

        $this->actingAs($this->user)->get(route('generations.show', $generation))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generation.error.code', 'internal_error')
                ->where('generation.actual_charge', '0.000000000000'));

        $this->actingAs($this->user)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.totals.spent', '0.000000000000')
                ->where('stats.totals.success_rate', 0));
    }

    public function test_retry_prefills_the_form_and_creates_a_new_generation(): void
    {
        $failed = $this->submit();
        $this->sendSignedEvent(F::event('generation.failed', F::generation('failed'), 'evt_f'));

        $this->actingAs($this->user)->get(route('generations.create', ['from' => $failed->uuid]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('prefill.prompt', 'A lighthouse on a cliff at dawn, oil painting')
                ->where('prefill.quality', 'high')
                ->where('prefill.reference_images', ['https://example.com/style.png']));

        $retry = $this->submit();

        $this->assertNotSame($failed->id, $retry->id);
        $this->assertNotSame($failed->idempotency_key, $retry->idempotency_key);
        $this->assertSame(GenerationStatus::Failed, $failed->fresh()->status);
    }

    public function test_prefill_ignores_other_users_generations(): void
    {
        $theirs = Generation::factory()->create();

        $this->actingAs($this->user)->get(route('generations.create', ['from' => $theirs->uuid]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null));
    }

    public function test_estimate_endpoint_validates_input(): void
    {
        $this->actingAs($this->user)->getJson('/generate/estimate?characters=-1&size=1024x1024&quality=low&references=0')->assertStatus(422);
        $this->actingAs($this->user)->getJson('/generate/estimate?characters=10&size=2048x2048&quality=low&references=0')->assertStatus(422);
        $this->actingAs($this->user)->getJson('/generate/estimate?characters=10&size=1024x1024&quality=low&references=5')->assertStatus(422);
    }

    public function test_estimate_endpoint_requires_authentication(): void
    {
        $this->getJson('/generate/estimate?characters=10&size=1024x1024&quality=low&references=0')->assertStatus(401);
    }
}
