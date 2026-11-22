<?php

use Illuminate\Support\Facades\Schedule;

// Webhook events that were stored but never processed (queue outage, crash).
Schedule::command('pixelforge:process-pending-webhooks')->everyFiveMinutes()->withoutOverlapping();
