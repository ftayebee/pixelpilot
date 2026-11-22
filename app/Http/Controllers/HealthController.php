<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Liveness and dependency check for load balancers and uptime monitors.
 * Reports only pass/fail per dependency, never configuration values.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseIsReachable(),
            'pixelforge_configured' => filled(config('pixelforge.api_key')) && filled(config('pixelforge.webhook_secret')),
        ];

        $healthy = $checks['database'];

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'time' => now()->toIso8601ZuluString(),
        ], $healthy ? 200 : 503);
    }

    private function databaseIsReachable(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
