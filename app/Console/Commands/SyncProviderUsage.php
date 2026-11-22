<?php

namespace App\Console\Commands;

use App\Services\Admin\ProviderUsageSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pixelforge:sync-usage {--days=7 : How many UTC days back to sync, including today}')]
#[Description('Copy Pixelforge usage (GET /v1/usage) for this API key, for reconciliation')]
class SyncProviderUsage extends Command
{
    public function handle(ProviderUsageSync $sync): int
    {
        if (blank(config('pixelforge.api_key'))) {
            $this->warn('PIXELFORGE_API_KEY is not set; nothing to sync.');

            return self::SUCCESS;
        }

        $end = CarbonImmutable::now('UTC')->startOfDay();
        $start = $end->subDays(max(1, (int) $this->option('days')) - 1);

        $days = $sync->sync($start, $end);
        $this->info("Synced {$days} day(s), {$start->toDateString()} to {$end->toDateString()}.");

        return self::SUCCESS;
    }
}
