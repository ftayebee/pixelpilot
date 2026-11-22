<?php

use Illuminate\Support\Facades\Schedule;

// Fallback for missed webhooks: check overdue generations with Pixelforge.
Schedule::command('pixelforge:poll-generations')->everyMinute()->withoutOverlapping();

// Webhook events that were stored but never processed (queue outage, crash).
Schedule::command('pixelforge:process-pending-webhooks')->everyFiveMinutes()->withoutOverlapping();

// Pixelforge's usage report, for the admin reconciliation view. Recent days
// can still change (late completions), so the last week is re-synced.
Schedule::command('pixelforge:sync-usage --days=7')->hourly()->withoutOverlapping();
