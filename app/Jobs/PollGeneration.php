<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Generations\GenerationPoller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PollGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $generationId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("poll-generation:{$this->generationId}"))->dontRelease()->expireAfter(120)];
    }

    public function handle(GenerationPoller $poller): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation !== null) {
            $poller->poll($generation);
        }
    }
}
