<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Pixelforge\Webhooks\GenerationNotFoundYet;
use App\Services\Pixelforge\Webhooks\WebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes one stored webhook event. Retried with backoff; the most common
 * reason is a webhook that arrived before we saved the create response.
 * If every attempt fails the event is kept (marked unmatched/failed), and
 * the polling fallback still brings the generation to its final state.
 */
class ProcessPixelforgeEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $webhookEventId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 60, 300, 900];
    }

    public function handle(WebhookProcessor $processor): void
    {
        $processor->process($this->webhookEventId);
    }

    public function failed(?Throwable $e): void
    {
        app(WebhookProcessor::class)->markFailed(
            $this->webhookEventId,
            $e instanceof GenerationNotFoundYet ? WebhookEvent::UNMATCHED : WebhookEvent::FAILED,
            $e?->getMessage() ?? 'Unknown error',
        );
    }
}
