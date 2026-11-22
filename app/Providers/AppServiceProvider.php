<?php

namespace App\Providers;

use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\Webhooks\PixelforgeWebhookVerifier;
use App\Services\Pricing\PricingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: config('pixelforge.base_url'),
            apiKey: config('pixelforge.api_key'),
            timeout: config('pixelforge.timeout'),
            connectTimeout: config('pixelforge.connect_timeout'),
            maxAttempts: config('pixelforge.retries.max_attempts'),
            maxRetryWait: config('pixelforge.retries.max_wait_seconds'),
        ));

        $this->app->bind(PixelforgeWebhookVerifier::class, fn () => new PixelforgeWebhookVerifier(
            secret: config('pixelforge.webhook_secret'),
            toleranceSeconds: config('pixelforge.webhook_tolerance'),
        ));

        $this->app->singleton(PricingService::class, fn () => new PricingService(
            markup: (string) config('pixelforge.markup_multiplier'),
        ));
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => Password::min(8)->letters()->numbers());
    }
}
