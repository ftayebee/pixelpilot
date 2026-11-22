<?php

namespace Tests\Feature\Webhooks;

use App\Enums\GenerationStatus;
use App\Jobs\ProcessPixelforgeEvent;
use App\Models\Generation;
use App\Models\GenerationCharge;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\Webhooks\GenerationNotFoundYet;
use App\Services\Pixelforge\Webhooks\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeFixtures as F;
use Tests\Support\SendsPixelforgeWebhooks;
use Tests\TestCase;

class WebhookProcessingTest extends TestCase
{
    use RefreshDatabase;
    use SendsPixelforgeWebhooks;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generation = Generation::factory()->create([
            'provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        ]);
    }

    private function event(string $status, string $eventId, array $overrides = []): array
    {
        $type = 'generation.'.$status;

        return F::event($type, F::generation($status, $overrides), $eventId);
    }

    public function test_event_is_stored_processed_and_answered_with_204(): void
    {
        $this->sendSignedEvent($this->event('succeeded', 'evt_1'))->assertNoContent();

        $event = WebhookEvent::sole();
        $this->assertSame('evt_1', $event->event_id);
        $this->assertSame(WebhookEvent::PROCESSED, $event->status);
        $this->assertSame($this->generation->id, $event->generation_id);
        $this->assertNotNull($event->processed_at);

        $this->generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $this->generation->status);
        $this->assertSame('0.381887500000', $this->generation->actual_charge);
    }

    public function test_raw_payload_is_kept_verbatim(): void
    {
        $event = $this->event('processing', 'evt_raw');
        $body = json_encode($event, JSON_UNESCAPED_SLASHES);

        $this->postWebhook($body, $this->signature($body))->assertNoContent();

        $this->assertSame($body, WebhookEvent::sole()->payload);
    }

    public function test_processing_is_queued_not_done_inline_when_a_queue_is_used(): void
    {
        Queue::fake();

        $this->sendSignedEvent($this->event('succeeded', 'evt_q'))->assertNoContent();

        Queue::assertPushed(ProcessPixelforgeEvent::class);
        $this->assertSame(WebhookEvent::PENDING, WebhookEvent::sole()->status);
        $this->assertSame(GenerationStatus::Queued, $this->generation->fresh()->status);
    }

    public function test_duplicate_delivery_is_recorded_once_and_charged_once(): void
    {
        $event = $this->event('succeeded', 'evt_dup');

        $this->sendSignedEvent($event, attempt: 1)->assertNoContent();
        $this->sendSignedEvent($event, attempt: 2)->assertNoContent();
        $this->sendSignedEvent($event, attempt: 2)->assertNoContent();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(3, WebhookEvent::sole()->deliveries);
        $this->assertSame(1, GenerationCharge::count());
        $this->assertSame('0.381887500000', $this->generation->fresh()->actual_charge);
    }

    public function test_out_of_order_processing_after_succeeded_is_ignored(): void
    {
        $this->sendSignedEvent($this->event('succeeded', 'evt_b'))->assertNoContent();
        $this->sendSignedEvent($this->event('processing', 'evt_a'))->assertNoContent();

        $this->generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $this->generation->status);
        $this->assertSame(2, WebhookEvent::where('status', WebhookEvent::PROCESSED)->count());
        $this->assertSame(1, GenerationCharge::count());
    }

    public function test_failed_then_late_succeeded_stays_failed_and_free(): void
    {
        $this->sendSignedEvent($this->event('failed', 'evt_f'))->assertNoContent();
        $this->sendSignedEvent($this->event('succeeded', 'evt_s'))->assertNoContent();

        $this->generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $this->generation->status);
        $this->assertSame('0.000000000000', $this->generation->actual_charge);
        $this->assertSame(0, GenerationCharge::count());
    }

    public function test_webhook_arriving_before_the_create_response_is_matched_by_metadata(): void
    {
        $pending = Generation::factory()->submitting()->create(['prompt' => 'Before response']);

        $this->sendSignedEvent(F::event('generation.processing', F::generation('processing', [
            'id' => 'gen_RACE',
            'prompt' => 'Before response',
            'metadata' => ['pixelpilot_generation' => $pending->uuid],
        ]), 'evt_race'))->assertNoContent();

        $pending->refresh();
        $this->assertSame('gen_RACE', $pending->provider_generation_id);
        $this->assertSame(GenerationStatus::Processing, $pending->status);
    }

    public function test_metadata_with_a_mismatched_prompt_is_not_trusted(): void
    {
        $pending = Generation::factory()->submitting()->create(['prompt' => 'Mine']);

        $this->sendSignedEvent(F::event('generation.processing', F::generation('processing', [
            'id' => 'gen_OTHER',
            'prompt' => 'Something else',
            'metadata' => ['pixelpilot_generation' => $pending->uuid],
        ]), 'evt_mismatch'))->assertNoContent();

        $this->assertNull($pending->fresh()->provider_generation_id);
        $this->assertNotSame(WebhookEvent::PROCESSED, WebhookEvent::sole()->status);
    }

    public function test_unknown_generation_keeps_the_event_for_retry(): void
    {
        Queue::fake();

        $this->sendSignedEvent(F::event('generation.succeeded', F::generation('succeeded', ['id' => 'gen_UNKNOWN']), 'evt_unknown'))
            ->assertNoContent();

        $event = WebhookEvent::sole();
        $this->assertSame(WebhookEvent::PENDING, $event->status);

        $this->expectException(GenerationNotFoundYet::class);
        app(WebhookProcessor::class)->process($event->id);
    }

    public function test_event_that_never_matches_is_marked_unmatched_after_retries(): void
    {
        Queue::fake();
        $this->sendSignedEvent(F::event('generation.succeeded', F::generation('succeeded', ['id' => 'gen_GONE']), 'evt_gone'));
        $event = WebhookEvent::sole();

        (new ProcessPixelforgeEvent($event->id))->failed(new GenerationNotFoundYet('nope'));

        $this->assertSame(WebhookEvent::UNMATCHED, $event->fresh()->status);
    }

    public function test_ping_events_are_stored_and_ignored(): void
    {
        $this->sendSignedEvent(F::event('ping', [], 'evt_ping'))->assertNoContent();

        $this->assertSame(WebhookEvent::IGNORED, WebhookEvent::sole()->status);
    }

    public function test_signed_but_malformed_events_are_rejected_and_not_stored(): void
    {
        $body = '{"object":"event","type":"generation.succeeded"}';

        $this->postWebhook($body, $this->signature($body))->assertStatus(422);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_reprocessing_a_finished_event_is_a_no_op(): void
    {
        $this->sendSignedEvent($this->event('succeeded', 'evt_once'));
        $event = WebhookEvent::sole();

        app(WebhookProcessor::class)->process($event->id);
        app(WebhookProcessor::class)->process($event->id);

        $this->assertSame(1, GenerationCharge::count());
        $this->assertSame(1, $event->fresh()->processing_attempts);
    }

    public function test_pending_events_sweeper_requeues_stale_events(): void
    {
        Queue::fake();
        $this->sendSignedEvent($this->event('processing', 'evt_stale'));
        WebhookEvent::query()->update(['last_received_at' => now()->subMinutes(10)]);
        Queue::fake();

        $this->artisan('pixelforge:process-pending-webhooks')->assertSuccessful();

        Queue::assertPushed(ProcessPixelforgeEvent::class, 1);
    }
}
