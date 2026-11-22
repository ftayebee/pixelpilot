<?php

namespace App\Http\Middleware;

use App\Services\Pixelforge\Webhooks\InvalidWebhookSignature;
use App\Services\Pixelforge\Webhooks\PixelforgeWebhookVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any webhook that isn't signed by Pixelforge before the
 * controller sees it. Verification uses the raw request body.
 */
class VerifyPixelforgeSignature
{
    public function __construct(private readonly PixelforgeWebhookVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->verifier->verify($request->getContent(), $request->header(PixelforgeWebhookVerifier::HEADER));
        } catch (InvalidWebhookSignature $e) {
            Log::warning('Rejected Pixelforge webhook.', [
                'reason' => $e->getMessage(),
                'event_id' => $request->header('Pixelforge-Event-Id'),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        return $next($request);
    }
}
