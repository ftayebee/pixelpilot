<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pixelforge API
    |--------------------------------------------------------------------------
    |
    | Use one API key per environment. Each key has its own webhook URL,
    | signing secret and usage report, so local events never reach the
    | deployed app and GET /v1/usage reconciles per environment.
    |
    */

    'base_url' => rtrim((string) env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'), '/'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Seconds to wait for a response, and to establish the connection.
    'timeout' => (int) env('PIXELFORGE_TIMEOUT', 30),
    'connect_timeout' => (int) env('PIXELFORGE_CONNECT_TIMEOUT', 10),

    // Webhooks signed more than this many seconds ago (or ahead) are rejected.
    'webhook_tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),

    // Retries for requests that are documented as safe to retry (429, 503,
    // connection failures). Generation creation is only retried with the
    // same Idempotency-Key.
    'retries' => [
        'max_attempts' => (int) env('PIXELFORGE_MAX_ATTEMPTS', 3),
        'max_wait_seconds' => (int) env('PIXELFORGE_MAX_RETRY_WAIT', 5),
    ],

    // Pricing endpoint responses are cached briefly; generations are always
    // billed at the rates Pixelforge returns on the generation itself.
    'pricing_cache_seconds' => (int) env('PIXELFORGE_PRICING_CACHE_SECONDS', 300),

    // Customer price = provider cost x this multiplier (25% markup).
    'markup_multiplier' => '1.25',

    // Polling fallback for generations whose webhooks never arrived.
    'polling' => [
        'first_after_seconds' => 45,
        'max_interval_seconds' => 600,
        'give_up_after_hours' => 48,
        'batch_size' => 20,
    ],

];
