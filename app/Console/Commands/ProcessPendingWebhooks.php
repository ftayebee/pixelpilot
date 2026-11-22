<?php

namespace App\Console\Commands;

use App\Jobs\ProcessPixelforgeEvent;
use App\Models\WebhookEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Safety net for verified events that were stored but never processed
 * (queue outage, worker crash). Runs on the scheduler.
 */
#[Signature('pixelforge:process-pending-webhooks {--older-than=120 : Only events received at least this many seconds ago}')]
#[Description('Re-queue stored Pixelforge webhook events that are still pending')]
class ProcessPendingWebhooks extends Command
{
    public function handle(): int
    {
        $count = 0;

        WebhookEvent::where('status', WebhookEvent::PENDING)
            ->where('last_received_at', '<=', now()->subSeconds((int) $this->option('older-than')))
            ->where('received_at', '>=', now()->subDays(3))
            ->orderBy('id')
            ->limit(200)
            ->pluck('id')
            ->each(function (int $id) use (&$count) {
                ProcessPixelforgeEvent::dispatch($id);
                $count++;
            });

        $this->info("Queued {$count} pending webhook event(s).");

        return self::SUCCESS;
    }
}
