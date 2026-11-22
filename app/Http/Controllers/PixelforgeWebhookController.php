<?php

namespace App\Http\Controllers;

use App\Services\Pixelforge\Webhooks\WebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Receives Pixelforge webhooks. Only signed requests reach this point
 * (see VerifyPixelforgeSignature). The event is stored and queued, and
 * Pixelforge gets a 204 straight away, including for events it has
 * already delivered.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookInbox $inbox): Response|JsonResponse
    {
        $attempt = $request->header('Pixelforge-Delivery-Attempt');

        try {
            $inbox->receive($request->getContent(), is_numeric($attempt) ? (int) $attempt : null);
        } catch (InvalidArgumentException $e) {
            Log::error('Signed Pixelforge webhook with an unexpected shape.', ['reason' => $e->getMessage()]);

            return response()->json(['error' => 'Malformed event.'], 422);
        }

        return response()->noContent();
    }
}
