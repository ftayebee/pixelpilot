<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\PollGeneration;
use App\Models\Generation;
use App\Models\GenerationCharge;
use App\Services\Generations\GenerationPoller;
use App\Services\Generations\GenerationSubmitter;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\PixelforgeFixtures as F;
use Tests\TestCase;

class PollingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function due(array $attributes = [], string $state = 'processing'): Generation
    {
        return Generation::factory()->{$state}()->create([
            'provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'next_poll_at' => now()->subSecond(),
            ...$attributes,
        ]);
    }

    public function test_only_due_pending_generations_are_claimed(): void
    {
        $due = $this->due();
        $this->due(['provider_generation_id' => 'gen_later', 'next_poll_at' => now()->addMinute()]);
        Generation::factory()->succeeded()->create(['next_poll_at' => now()->subMinute()]);
        Generation::factory()->failed()->create(['next_poll_at' => now()->subMinute()]);
        Generation::factory()->processing()->create(['provider_generation_id' => 'gen_nopoll', 'next_poll_at' => null]);

        $claimed = app(GenerationPoller::class)->claimDue(10);

        $this->assertSame([$due->id], $claimed->all());
    }

    public function test_claiming_pushes_the_next_poll_out_with_backoff(): void
    {
        $generation = $this->due();
        $poller = app(GenerationPoller::class);

        $poller->claimDue(10);
        $generation->refresh();
        $this->assertSame(1, $generation->poll_attempts);
        $this->assertEqualsWithDelta(45, now()->diffInSeconds($generation->next_poll_at), 2);

        $generation->refresh()->forceFill(['next_poll_at' => now()->subMinute()])->save();
        $poller->claimDue(10);
        $this->assertEqualsWithDelta(90, now()->diffInSeconds($generation->fresh()->next_poll_at), 2);

        $generation->refresh()->forceFill(['next_poll_at' => now()->subMinutes(2), 'poll_attempts' => 9])->save();
        $poller->claimDue(10);
        $this->assertEqualsWithDelta(600, now()->diffInSeconds($generation->fresh()->next_poll_at), 2);
    }

    public function test_a_claimed_generation_is_not_claimed_again_by_a_second_run(): void
    {
        $this->due();
        $poller = app(GenerationPoller::class);

        $this->assertCount(1, $poller->claimDue(10));
        $this->assertCount(0, $poller->claimDue(10));
    }

    public function test_batch_size_is_respected(): void
    {
        foreach (range(1, 5) as $i) {
            $this->due(['provider_generation_id' => "gen_{$i}"]);
        }

        $this->assertCount(3, app(GenerationPoller::class)->claimDue(3));
    }

    public function test_poll_recovers_a_missed_success_and_charges_once(): void
    {
        $generation = $this->due();
        Http::fake([F::BASE.'/generations/*' => Http::response(F::generation('succeeded'))]);

        $this->assertSame('updated', app(GenerationPoller::class)->poll($generation));
        $this->assertSame('final', app(GenerationPoller::class)->poll($generation->fresh()));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame('0.381887500000', $generation->actual_charge);
        $this->assertNull($generation->next_poll_at);
        $this->assertSame(1, GenerationCharge::count());
        Http::assertSentCount(1); // final generations aren't polled again
    }

    public function test_poll_recovers_a_missed_failure_at_zero_cost(): void
    {
        $generation = $this->due();
        Http::fake([F::BASE.'/generations/*' => Http::response(F::generation('failed'))]);

        app(GenerationPoller::class)->poll($generation);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('0.000000000000', $generation->actual_charge);
    }

    public function test_poll_errors_leave_the_generation_pending_for_the_next_attempt(): void
    {
        $generation = $this->due();
        Http::fake([F::BASE.'/generations/*' => Http::response(F::error('overloaded', 'api_error', 'busy'), 503, ['Retry-After' => '2'])]);

        $this->assertSame('error:overloaded', app(GenerationPoller::class)->poll($generation));
        $this->assertSame(GenerationStatus::Processing, $generation->fresh()->status);
    }

    public function test_unconfirmed_submission_is_resubmitted_with_the_same_key(): void
    {
        $generation = Generation::factory()->submitting()->create(['next_poll_at' => now()->subSecond()]);
        Http::fake([F::BASE.'/generations' => Http::response(F::generation('processing'), 202, ['Idempotent-Replayed' => 'true'])]);

        $this->assertSame('resubmitted:accepted', app(GenerationPoller::class)->poll($generation));

        $this->assertSame(GenerationStatus::Processing, $generation->fresh()->status);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', $generation->idempotency_key));
    }

    public function test_generations_pending_for_too_long_are_closed_as_failed_and_free(): void
    {
        $generation = $this->due(['created_at' => now()->subHours(49)]);
        Http::fake();

        $this->assertSame('gave_up', app(GenerationPoller::class)->poll($generation));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('timed_out', $generation->error_code);
        $this->assertSame('0.000000000000', $generation->actual_charge);
        Http::assertNothingSent();
    }

    public function test_command_queues_due_generations(): void
    {
        Queue::fake();
        $generation = $this->due();

        $this->artisan('pixelforge:poll-generations')->assertSuccessful();

        Queue::assertPushed(PollGeneration::class, fn ($job) => $job->generationId === $generation->id);
    }

    public function test_command_backs_off_near_the_rate_limit(): void
    {
        Queue::fake();
        $this->due();
        Cache::put(PixelforgeClient::RATE_LIMIT_CACHE_KEY, 3, 60);

        $this->artisan('pixelforge:poll-generations')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_poll_job_end_to_end(): void
    {
        $generation = $this->due();
        Http::fake([F::BASE.'/generations/*' => Http::response(F::generation('succeeded'))]);

        $this->artisan('pixelforge:poll-generations')->assertSuccessful();

        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()->status);
    }

    public function test_accepted_submission_schedules_the_first_poll(): void
    {
        Http::fake([
            F::BASE.'/pricing' => Http::response(F::pricing()),
            F::BASE.'/generations' => Http::response(F::generation('queued'), 202),
        ]);
        $generation = Generation::factory()->submitting()->create();

        app(GenerationSubmitter::class)->submit($generation);

        $this->assertEqualsWithDelta(45, now()->diffInSeconds($generation->fresh()->next_poll_at), 2);
    }
}
