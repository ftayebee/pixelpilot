<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generations\GenerationSubmitter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Retries a submission that hit a retryable error (overload, rate or
 * concurrency limit, network failure). The submitter schedules the next
 * attempt itself, with backoff, up to its attempt cap. Overlapping attempts
 * for the same generation are skipped; the idempotency key would make them
 * harmless anyway, but there's no reason to spend rate limit on them.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $generationId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("submit-generation:{$this->generationId}"))->dontRelease()->expireAfter(120)];
    }

    public function handle(GenerationSubmitter $submitter): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation === null || $generation->status !== GenerationStatus::Submitting) {
            return;
        }

        $submitter->submit($generation);
    }
}
