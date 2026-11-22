<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Pixelforge webhooks. Only signed requests reach this point
 * (see VerifyPixelforgeSignature).
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return response()->noContent();
    }
}
