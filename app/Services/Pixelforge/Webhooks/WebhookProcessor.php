<?php

namespace App\Services\Pixelforge\Webhooks;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Generations\GenerationSynchronizer;
use App\Services\Pixelforge\Data\ProviderGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Applies one stored webhook event. Safe to run any number of times and
 * concurrently: the event row is locked and finished events are skipped,
 * and the generation itself is updated through GenerationSynchronizer.
 */
class WebhookProcessor
{
    public function __construct(private readonly GenerationSynchronizer $synchronizer) {}

    /**
     * @return string the event's resulting status
     *
     * @throws GenerationNotFoundYet when the generation can't be matched (yet)
     */
    public function process(int $webhookEventId): string
    {
        return DB::transaction(function () use ($webhookEventId) {
            /** @var WebhookEvent|null $event */
            $event = WebhookEvent::whereKey($webhookEventId)->lockForUpdate()->first();

            if ($event === null || $event->isFinished()) {
                return $event->status ?? WebhookEvent::IGNORED;
            }

            $event->processing_attempts++;

            if (! in_array($event->type, WebhookInbox::GENERATION_EVENTS, true)) {
                return $this->finish($event, WebhookEvent::IGNORED);
            }

            $snapshot = ProviderGeneration::fromArray($event->decodedPayload()['data']['object']);
            $generation = $this->findGeneration($snapshot);

            if ($generation === null) {
                throw new GenerationNotFoundYet("No generation for {$snapshot->id} (event {$event->event_id}).");
            }

            $this->synchronizer->apply($generation, $snapshot, "webhook:{$event->event_id}");

            $event->generation_id = $generation->id;

            return $this->finish($event, WebhookEvent::PROCESSED);
        });
    }

    public function markFailed(int $webhookEventId, string $status, string $error): void
    {
        WebhookEvent::whereKey($webhookEventId)
            ->whereNotIn('status', [WebhookEvent::PROCESSED, WebhookEvent::IGNORED])
            ->update(['status' => $status, 'last_error' => Str::limit($error, 1000), 'updated_at' => now()]);
    }

    /**
     * By Pixelforge id first. If the create response hasn't been saved yet
     * (the webhook won the race), fall back to the id we put in metadata.
     */
    private function findGeneration(ProviderGeneration $snapshot): ?Generation
    {
        $generation = Generation::where('provider_generation_id', $snapshot->id)->first();

        if ($generation !== null) {
            return $generation;
        }

        $uuid = $snapshot->metadata['pixelpilot_generation'] ?? null;

        if (! is_string($uuid) || ! Str::isUuid($uuid)) {
            return null;
        }

        $generation = Generation::where('uuid', $uuid)->first();

        // The metadata must agree with what we sent: same prompt, not linked to another generation.
        if ($generation === null
            || ($generation->provider_generation_id !== null && $generation->provider_generation_id !== $snapshot->id)
            || $generation->prompt !== $snapshot->prompt) {
            Log::warning('Webhook metadata did not match a generation.', ['provider_id' => $snapshot->id]);

            return null;
        }

        return $generation;
    }

    private function finish(WebhookEvent $event, string $status): string
    {
        $event->status = $status;
        $event->processed_at = now();
        $event->last_error = null;
        $event->save();

        return $status;
    }
}
