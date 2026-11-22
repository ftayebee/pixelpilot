<?php

namespace App\Console\Commands;

use App\Jobs\PollGeneration;
use App\Services\Generations\GenerationPoller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pixelforge:poll-generations {--limit= : Maximum generations to poll this run}')]
#[Description('Check Pixelforge for generations whose webhooks are overdue')]
class PollPendingGenerations extends Command
{
    public function handle(GenerationPoller $poller): int
    {
        if (! $poller->hasRateLimitHeadroom()) {
            $this->warn('Close to the Pixelforge rate limit; skipping this run.');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?: config('pixelforge.polling.batch_size'));
        $ids = $poller->claimDue($limit);

        $ids->each(fn (int $id) => PollGeneration::dispatch($id));

        $this->info("Queued {$ids->count()} generation(s) for a status check.");

        return self::SUCCESS;
    }
}
