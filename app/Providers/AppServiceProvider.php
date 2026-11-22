<?php

namespace App\Providers;

use App\Services\Pixelforge\PixelforgeClient;
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
