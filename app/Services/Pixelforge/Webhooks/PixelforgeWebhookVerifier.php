<?php

namespace App\Services\Pixelforge\Webhooks;

/**
 * Verifies the Pixelforge-Signature header on incoming webhooks.
 *
 * Header format: "t=<unix timestamp>,v1=<hex HMAC-SHA256>", where the HMAC
 * is computed over "<timestamp>.<raw request body>" with the signing secret
 * of the API key that created the generation.
 *
 * The raw body must be passed exactly as received: decoding and
 * re-encoding JSON would change whitespace or key order and break the
 * signature.
 */
class PixelforgeWebhookVerifier
{
    public const HEADER = 'Pixelforge-Signature';

    public function __construct(
        private readonly ?string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    /**
     * @throws InvalidWebhookSignature
     */
    public function verify(string $rawPayload, ?string $signatureHeader, ?int $now = null): void
    {
        if ($this->secret === null || $this->secret === '') {
            // Fail closed: without a secret we can't tell real events from forged ones.
            throw new InvalidWebhookSignature('Webhook signing secret is not configured.');
        }

        if ($signatureHeader === null || trim($signatureHeader) === '') {
            throw new InvalidWebhookSignature('Missing signature header.');
        }

        [$timestamp, $signatures] = $this->parse($signatureHeader);

        if ($timestamp === null) {
            throw new InvalidWebhookSignature('Missing or invalid timestamp.');
        }

        if ($signatures === []) {
            throw new InvalidWebhookSignature('Missing v1 signature.');
        }

        $now ??= time();
        if (abs($now - $timestamp) > $this->toleranceSeconds) {
            throw new InvalidWebhookSignature('Timestamp outside the allowed window.');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawPayload}", $this->secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new InvalidWebhookSignature('Signature does not match.');
    }

    /**
     * @return array{0: int|null, 1: list<string>}
     */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && preg_match('/^\d{1,12}$/', $value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/', strtolower($value))) {
                $signatures[] = strtolower($value);
            }
        }

        return [$timestamp, $signatures];
    }
}
