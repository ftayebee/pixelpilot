<?php

use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;

/*
| Loaded without the "web" middleware group: Pixelforge has no session or
| CSRF token, and the signature is what authenticates the request.
*/

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware(VerifyPixelforgeSignature::class)
    ->name('webhooks.pixelforge');
