<?php

namespace Tests\Feature\Scenarios;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\GenerationCharge;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Generations\GenerationPoller;
use App\Services\Generations\GenerationSubmitter;
use App\Services\Pixelforge\Webhooks\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\PixelforgeFixtures as F;
use Tests\Support\SendsPixelforgeWebhooks;
use Tests\TestCase;

/**
 * The assessment's failure scenarios that aren't already covered by the
 * focused test classes. See docs/test-report.md for the full map.
 */
class FailureScenariosTest extends TestCase
{
    use RefreshDatabase;
    use SendsPixelforgeWebhooks;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->user = User::factory()->withCredits('10')->create();
    }

    private function submit(string $prompt = 'A lighthouse on a cliff at dawn, oil painting'): TestResponse
    {
        return $this->actingAs($this->user)->from('/generate')->post('/generations', [
            'prompt' => $prompt,
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'client_request_id' => (string) Str::uuid(),
        ]);
    }

    /** Scenario 3: per-minute rate limit with a long Retry-After. */
    public function test_rate_limit_with_long_retry_after_is_deferred_to_the_queue(): void
    {
        Queue::fake();
        Http::fake([
            F::BASE.'/pricing' => Http::response(F::pricing()),
            F::BASE.'/generations' => Http::response(F::error('rate_limit_exceeded', 'rate_limit_error', 'Too many requests this minute.'), 429, ['Retry-After' => '37']),
        ]);

        $this->submit()->assertRedirect()->assertSessionHas('success');

        $generation = Generation::sole();
        $this->assertSame(GenerationStatus::Submitting, $generation->status);
        Http::assertSentCount(2); // pricing + one create: no inline waiting for 37 seconds
        Queue::assertPushed(SubmitGeneration::class, function (SubmitGeneration $job) {
            return $job->delay !== null && now()->diffInSeconds($job->delay) >= 36;
        });
    }

    /** Scenario 7: a [slow] generation stays processing through polls, then succeeds. */
    public function test_slow_generation_is_not_failed_prematurely(): void
    {
        $generation = Generation::factory()->for($this->user)->processing()->create([
            'provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'prompt' => 'A lighthouse [slow]',
            'next_poll_at' => now()->subSecond(),
        ]);
        Http::fake([F::BASE.'/generations/*' => Http::response(F::generation('processing'))]);

        $this->assertSame('unchanged', app(GenerationPoller::class)->poll($generation));
        $this->travel(90)->seconds();
        $this->assertSame('unchanged', app(GenerationPoller::class)->poll($generation->fresh()));
        $this->assertSame(GenerationStatus::Processing, $generation->fresh()->status);

        $this->sendSignedEvent(F::event('generation.succeeded', F::generation('succeeded'), 'evt_slow_done'));

        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()->status);
        $this->assertSame(1, GenerationCharge::count());
    }

    /** Scenario 12: Pixelforge retries a delivery we couldn't handle the first time. */
    public function test_redelivered_event_is_processed_once_the_generation_can_be_matched(): void
    {
        Queue::fake(); // keep the first attempt pending, as it would be between retries
        $event = F::event('generation.succeeded', F::generation('succeeded', ['id' => 'gen_LATE']), 'evt_retry_me');
        $this->sendSignedEvent($event, attempt: 1)->assertNoContent();
        $this->assertSame(WebhookEvent::PENDING, WebhookEvent::sole()->status);

        // Meanwhile the create response is saved.
        $generation = Generation::factory()->for($this->user)->create(['provider_generation_id' => 'gen_LATE']);

        // Pixelforge's automatic retry (attempt 2) arrives; the queued job runs.
        $this->sendSignedEvent($event, attempt: 2)->assertNoContent();
        app(WebhookProcessor::class)->process(WebhookEvent::sole()->id);

        $stored = WebhookEvent::sole();
        $this->assertSame(WebhookEvent::PROCESSED, $stored->status);
        $this->assertSame(2, $stored->deliveries);
        $this->assertSame(2, $stored->last_delivery_attempt);
        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()->status);
        $this->assertSame(1, GenerationCharge::count());
    }

    /** Scenario 13: the webhook lands while our create request is still in flight. */
    public function test_webhook_during_the_create_request_is_not_overwritten_by_the_response(): void
    {
        Http::fake([
            F::BASE.'/pricing' => Http::response(F::pricing()),
            F::BASE.'/generations' => function (Request $request) {
                // Pixelforge accepts, starts processing and sends the webhook before answering us.
                $snapshot = F::generation('processing', ['metadata' => $request['metadata'], 'prompt' => $request['prompt']]);
                $this->sendSignedEvent(F::event('generation.processing', $snapshot, 'evt_early'))->assertNoContent();

                return Http::response(F::generation('queued', ['metadata' => $request['metadata'], 'prompt' => $request['prompt']]), 202);
            },
        ]);

        $this->submit()->assertRedirect();

        $generation = Generation::sole();
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->provider_generation_id);
        // The later-arriving "queued" response must not drag it back from "processing".
        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertSame(WebhookEvent::PROCESSED, WebhookEvent::sole()->status);
    }

    /** Scenario 15: the create request times out twice, then the retry finds the original. */
    public function test_network_timeouts_on_create_never_produce_a_second_generation(): void
    {
        $attempts = 0;
        $keys = [];
        Http::fake([
            F::BASE.'/pricing' => Http::response(F::pricing()),
            F::BASE.'/generations' => function (Request $request) use (&$attempts, &$keys) {
                $attempts++;
                $keys[] = $request->header('Idempotency-Key')[0];
                if ($attempts < 3) {
                    throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
                }

                return Http::response(F::generation('queued'), 202, ['Idempotent-Replayed' => 'true']);
            },
        ]);

        $this->submit()->assertRedirect();

        $this->assertSame(1, Generation::count());
        $this->assertSame(3, $attempts);
        $this->assertCount(1, array_unique($keys));
        $this->assertSame(Generation::sole()->idempotency_key, $keys[0]);
        $this->assertSame(GenerationStatus::Queued, Generation::sole()->status);
    }

    /** Scenario 15b: every attempt times out; the generation waits for a retry rather than failing. */
    public function test_persistent_network_failure_leaves_the_generation_retryable(): void
    {
        Queue::fake();
        Http::fake([
            F::BASE.'/pricing' => Http::response(F::pricing()),
            F::BASE.'/generations' => fn () => throw new ConnectionException('Connection refused'),
        ]);

        $this->submit()->assertRedirect()->assertSessionHas('success');

        $generation = Generation::sole();
        $this->assertSame(GenerationStatus::Submitting, $generation->status);
        $this->assertNull($generation->actual_charge);
        Queue::assertPushed(SubmitGeneration::class);
    }

    /** Scenario 4 (exhausted): concurrency limit that never clears ends free, not stuck. */
    public function test_submission_gives_up_after_the_attempt_cap_at_no_cost(): void
    {
        Queue::fake();
        Http::fake([
            F::BASE.'/pricing' => Http::response(F::pricing()),
            F::BASE.'/generations' => Http::response(F::error('concurrency_limit_exceeded', 'rate_limit_error', 'Too many'), 429, ['Retry-After' => '5']),
        ]);
        $generation = Generation::factory()->for($this->user)->submitting()->create(['submit_attempts' => 7]);

        (new SubmitGeneration($generation->id))->handle(app(GenerationSubmitter::class));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('concurrency_limit_exceeded', $generation->error_code);
        $this->assertSame('0.000000000000', $generation->actual_charge);
    }

    /** Scenario 8 + 18: [duplicate] delivers every event twice, in any order. */
    public function test_duplicate_keyword_storm_charges_exactly_once(): void
    {
        $generation = Generation::factory()->for($this->user)->create(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
        $processing = F::event('generation.processing', F::generation('processing'), 'evt_dup_p');
        $succeeded = F::event('generation.succeeded', F::generation('succeeded'), 'evt_dup_s');

        foreach ([$succeeded, $processing, $succeeded, $processing, $succeeded] as $i => $event) {
            $this->sendSignedEvent($event, attempt: $i + 1)->assertNoContent();
        }

        $this->assertSame(2, WebhookEvent::count());
        $this->assertSame(1, GenerationCharge::count());
        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()->status);
        $this->assertSame('0.381887500000', $generation->fresh()->actual_charge);
    }
}
