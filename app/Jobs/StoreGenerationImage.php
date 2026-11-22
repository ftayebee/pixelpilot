<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Generations\GenerationImages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Keeps a copy of a succeeded generation's image after Pixelforge's URL
 * expires. Only dispatched when image storage is enabled.
 */
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function __construct(public readonly int $generationId) {}

    public function handle(GenerationImages $images): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation !== null) {
            $images->store($generation);
        }
    }
}
