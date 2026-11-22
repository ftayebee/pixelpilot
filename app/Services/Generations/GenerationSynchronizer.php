<?php

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\Data\ProviderGeneration;
use App\Services\Pricing\ChargeRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies what Pixelforge tells us (create response, webhook, poll) to a
 * local generation. Every source goes through here, so the rules live in
 * one place:
 *
 *  - the row is locked for the duration, so concurrent webhooks/polls for
 *    the same generation are serialised;
 *  - statuses only move forward, and succeeded/failed are final, so a stale
 *    or duplicated snapshot is a no-op;
 *  - the charge is computed from Pixelforge's reported usage and the rates
 *    on the generation, exactly once, when it first becomes succeeded;
 *  - a failed generation is recorded at $0.
 */
class GenerationSynchronizer
{
    public function __construct(private readonly ChargeRecorder $charges) {}

    /**
     * @return bool whether anything changed
     */
    public function apply(Generation $generation, ProviderGeneration $snapshot, string $source): bool
    {
        return DB::transaction(function () use ($generation, $snapshot, $source) {
            /** @var Generation $locked */
            $locked = Generation::whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->provider_generation_id !== null && $locked->provider_generation_id !== $snapshot->id) {
                Log::error('Provider generation id mismatch; snapshot ignored.', [
                    'generation' => $locked->uuid,
                    'source' => $source,
                ]);

                return false;
            }

            $changed = $this->link($locked, $snapshot);

            $current = $locked->status;
            $next = $snapshot->status;

            if ($current->canTransitionTo($next)) {
                match ($next) {
                    GenerationStatus::Queued => null,
                    GenerationStatus::Processing => $locked->started_at ??= $snapshot->startedAt,
                    GenerationStatus::Succeeded => $this->markSucceeded($locked, $snapshot),
                    GenerationStatus::Failed => $this->markFailed($locked, $snapshot),
                    GenerationStatus::Submitting => null,
                };

                $locked->status = $next;
                $changed = true;
            } elseif ($current === GenerationStatus::Succeeded && $next === GenerationStatus::Succeeded) {
                // Same final state seen again: only a fresh image URL may be taken; the charge never changes.
                $changed = $this->refreshImage($locked, $snapshot) || $changed;
            } elseif ($current !== $next) {
                Log::info('Ignored out-of-order or stale generation status.', [
                    'generation' => $locked->uuid,
                    'current' => $current->value,
                    'received' => $next->value,
                    'source' => $source,
                ]);
            }

            $locked->last_synced_at = now();
            if ($locked->status->isFinal()) {
                $locked->next_poll_at = null;
            }
            $locked->save();

            $generation->setRawAttributes($locked->getAttributes(), true);

            return $changed;
        });
    }

    private function link(Generation $generation, ProviderGeneration $snapshot): bool
    {
        $changed = false;

        if ($generation->provider_generation_id === null) {
            $generation->provider_generation_id = $snapshot->id;
            $changed = true;
        }

        // Rates are fixed by Pixelforge when the generation is created.
        if (empty($generation->rates) && $snapshot->rates !== []) {
            $generation->rates = $snapshot->rates;
            $changed = true;
        }

        if ($generation->provider_created_at === null && $snapshot->createdAt !== null) {
            $generation->provider_created_at = $snapshot->createdAt;
            $changed = true;
        }

        return $changed;
    }

    private function markSucceeded(Generation $generation, ProviderGeneration $snapshot): void
    {
        $this->charges->record($generation, $snapshot);

        $generation->forceFill([
            'started_at' => $generation->started_at ?? $snapshot->startedAt,
            'completed_at' => $snapshot->completedAt ?? now(),
            'error_code' => null,
            'error_message' => null,
        ]);

        $this->refreshImage($generation, $snapshot);
    }

    private function markFailed(Generation $generation, ProviderGeneration $snapshot): void
    {
        $generation->forceFill([
            'actual_cost' => '0',
            'actual_charge' => '0',
            'error_code' => $snapshot->errorCode ?? 'generation_failed',
            'error_message' => $snapshot->errorMessage ?? 'The generation failed.',
            'started_at' => $generation->started_at ?? $snapshot->startedAt,
            'completed_at' => $snapshot->completedAt ?? now(),
        ]);
    }

    private function refreshImage(Generation $generation, ProviderGeneration $snapshot): bool
    {
        if ($snapshot->imageUrl === null || $snapshot->imageUrl === $generation->image_url) {
            return false;
        }

        $generation->image_url = $snapshot->imageUrl;
        $generation->image_url_expires_at = $snapshot->imageUrlExpiresAt;

        return true;
    }
}
