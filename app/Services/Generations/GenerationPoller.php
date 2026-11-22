<?php

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fallback for missed webhooks: asks Pixelforge for the current state of
 * generations that should have finished by now.
 *
 * Polling is spaced out with exponential backoff per generation, capped at
 * a batch size per run, and skipped entirely when we're close to the
 * account's per-minute request limit. Webhooks remain the primary path.
 */
class GenerationPoller
{
    /** Leave headroom for user-facing requests (creating generations). */
    public const MIN_RATE_LIMIT_REMAINING = 15;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly GenerationSynchronizer $synchronizer,
    ) {}

    /**
     * Atomically claims due generations by pushing their next_poll_at into
     * the future. FOR UPDATE SKIP LOCKED means two schedulers (or two
     * servers) running at once claim different rows, never the same one.
     *
     * @return Collection<int, int> generation ids
     */
    public function claimDue(int $limit): Collection
    {
        $ids = DB::select(<<<'SQL'
            UPDATE generations
               SET poll_attempts = poll_attempts + 1,
                   next_poll_at = ?::timestamptz + make_interval(secs => LEAST(?::double precision, ?::double precision * power(2, LEAST(poll_attempts, 10)))),
                   updated_at = ?::timestamptz
             WHERE id IN (
                   SELECT id FROM generations
                    WHERE status IN ('submitting', 'queued', 'processing')
                      AND next_poll_at IS NOT NULL
                      AND next_poll_at <= ?::timestamptz
                    ORDER BY next_poll_at
                    LIMIT ?
                    FOR UPDATE SKIP LOCKED)
            RETURNING id
            SQL, [
            $now = now()->utc()->toIso8601String(),
            (int) config('pixelforge.polling.max_interval_seconds'),
            (int) config('pixelforge.polling.first_after_seconds'),
            $now,
            $now,
            $limit,
        ]);

        return collect($ids)->pluck('id')->map(fn ($id) => (int) $id);
    }

    public function hasRateLimitHeadroom(): bool
    {
        $remaining = Cache::get(PixelforgeClient::RATE_LIMIT_CACHE_KEY);

        return $remaining === null || (int) $remaining >= self::MIN_RATE_LIMIT_REMAINING;
    }

    /**
     * @return string what happened, for logs and tests
     */
    public function poll(Generation $generation): string
    {
        if ($generation->status->isFinal()) {
            return 'final';
        }

        if ($this->hasExpired($generation)) {
            $this->giveUp($generation);

            return 'gave_up';
        }

        // Never confirmed by Pixelforge: re-send with the same Idempotency-Key.
        // If the first request did get through, this returns that generation.
        if ($generation->provider_generation_id === null) {
            $result = app(GenerationSubmitter::class)->submit($generation);

            return 'resubmitted:'.$result->outcome;
        }

        try {
            $snapshot = $this->client->getGeneration($generation->provider_generation_id);
        } catch (PixelforgeException $e) {
            if ($e->errorCode === 'not_found') {
                Log::error('Pending generation not found at Pixelforge.', ['generation' => $generation->uuid]);
            }

            return 'error:'.($e->errorCode ?? 'unknown');
        }

        $changed = $this->synchronizer->apply($generation, $snapshot, 'poll');

        return $changed ? 'updated' : 'unchanged';
    }

    private function hasExpired(Generation $generation): bool
    {
        return $generation->created_at !== null
            && $generation->created_at->lt(now()->subHours((int) config('pixelforge.polling.give_up_after_hours')));
    }

    /**
     * Pixelforge finishes generations within minutes. One still pending
     * after two days isn't coming back, so we close it as failed (and free)
     * rather than poll forever.
     */
    private function giveUp(Generation $generation): void
    {
        DB::transaction(function () use ($generation) {
            $locked = Generation::whereKey($generation->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status->isFinal()) {
                return;
            }

            $locked->forceFill([
                'status' => GenerationStatus::Failed,
                'actual_cost' => '0',
                'actual_charge' => '0',
                'error_code' => 'timed_out',
                'error_message' => 'Pixelforge never reported a result for this image.',
                'completed_at' => now(),
                'next_poll_at' => null,
            ])->save();
        });

        Log::warning('Gave up waiting for a generation.', ['generation' => $generation->uuid]);
    }
}
