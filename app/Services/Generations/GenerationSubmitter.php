<?php

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Credits\CreditLedger;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends a local generation to Pixelforge.
 *
 * Every attempt for a generation uses the same Idempotency-Key, so if a
 * request times out after Pixelforge accepted it, the retry gets the same
 * generation back instead of creating (and billing) a second one.
 */
class GenerationSubmitter
{
    public const MAX_ATTEMPTS = 8;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly GenerationSynchronizer $synchronizer,
    ) {}

    public function submit(Generation $generation): SubmissionResult
    {
        if ($generation->status !== GenerationStatus::Submitting) {
            return SubmissionResult::accepted();
        }

        $generation->increment('submit_attempts');

        try {
            $result = $this->client->createGeneration($this->payload($generation), $generation->idempotency_key);
        } catch (PixelforgeException $e) {
            return $this->handleFailure($generation, $e);
        }

        $this->synchronizer->apply($generation, $result['generation'], 'create');

        // Webhooks should arrive first; this is when the poller checks if they haven't.
        if ($generation->status->isPending()) {
            $generation->forceFill([
                'next_poll_at' => now()->addSeconds(config('pixelforge.polling.first_after_seconds')),
                'poll_attempts' => 0,
            ])->save();
        }

        return SubmissionResult::accepted();
    }

    private function handleFailure(Generation $generation, PixelforgeException $e): SubmissionResult
    {
        if ($e->isRetryable() && $generation->submit_attempts < self::MAX_ATTEMPTS) {
            $delay = $this->retryDelay($generation->submit_attempts, $e->retryAfter);
            SubmitGeneration::dispatch($generation->id)->delay(now()->addSeconds($delay));

            // Safety net: if that job is lost, the poller re-submits (same key) a bit later.
            $generation->forceFill(['next_poll_at' => now()->addSeconds($delay + 120)])->save();

            return SubmissionResult::retrying($e->userMessage(), $delay);
        }

        $this->markRejected($generation, $e);

        return SubmissionResult::rejected($e->userMessage(), $e);
    }

    /**
     * Pixelforge didn't create anything (or we stopped trying), so the
     * generation is final and free.
     */
    private function markRejected(Generation $generation, PixelforgeException $e): void
    {
        DB::transaction(function () use ($generation, $e) {
            $locked = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            // A webhook may have linked it in the meantime; never override that.
            if ($locked->status !== GenerationStatus::Submitting) {
                return;
            }

            $locked->forceFill([
                'status' => GenerationStatus::Failed,
                'actual_cost' => '0',
                'actual_charge' => '0',
                'error_code' => $e->errorCode ?? ($e->connectionFailed ? 'connection_failed' : 'submission_failed'),
                'error_message' => $e->userMessage(),
                'completed_at' => now(),
                'next_poll_at' => null,
            ])->save();

            app(CreditLedger::class)->release($locked);

            $generation->setRawAttributes($locked->getAttributes(), true);
        });

        Log::warning('Generation submission rejected.', [
            'generation' => $generation->uuid,
            'code' => $e->errorCode,
            'status' => $e->status,
            'attempts' => $generation->submit_attempts,
        ]);
    }

    private function retryDelay(int $attempt, ?int $retryAfter): int
    {
        $backoff = min(300, 5 * (2 ** max(0, $attempt - 1)));

        return max($retryAfter ?? 0, $backoff);
    }

    /**
     * Must be identical on every attempt: Pixelforge answers 409 if the same
     * Idempotency-Key arrives with different parameters.
     *
     * @return array{prompt: string, size: string, quality: string, reference_images: list<string>, metadata: array<string, string>}
     */
    public function payload(Generation $generation): array
    {
        return [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => array_values($generation->reference_images ?? []),
            // Lets a webhook find this row even before the create response is saved.
            'metadata' => ['pixelpilot_generation' => $generation->uuid],
        ];
    }
}
