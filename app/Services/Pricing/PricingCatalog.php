<?php

namespace App\Services\Pricing;

use App\Services\Pixelforge\Data\Pricing;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Current Pixelforge pricing, cached briefly. If Pixelforge's pricing
 * endpoint is unreachable we fall back to the last copy we saw (for up to a
 * day) rather than refusing every estimate. Estimates are only estimates:
 * the charge always uses the rates Pixelforge returns on the generation.
 */
class PricingCatalog
{
    private const FRESH_KEY = 'pixelforge:pricing';

    private const LAST_KNOWN_KEY = 'pixelforge:pricing:last-known';

    public function __construct(private readonly PixelforgeClient $client) {}

    public function current(): Pricing
    {
        $cached = Cache::get(self::FRESH_KEY);
        if (is_array($cached)) {
            return Pricing::fromArray($cached);
        }

        try {
            $pricing = $this->client->pricing();
        } catch (PixelforgeException $e) {
            $lastKnown = Cache::get(self::LAST_KNOWN_KEY);

            if (is_array($lastKnown)) {
                Log::warning('Using last known Pixelforge pricing; pricing endpoint failed.', ['code' => $e->errorCode, 'status' => $e->status]);

                return Pricing::fromArray($lastKnown);
            }

            throw $e;
        }

        $payload = $this->toPayload($pricing);
        Cache::put(self::FRESH_KEY, $payload, now()->addSeconds(config('pixelforge.pricing_cache_seconds')));
        Cache::put(self::LAST_KNOWN_KEY, $payload, now()->addDay());

        return $pricing;
    }

    /**
     * Same shape as GET /v1/pricing so Pricing::fromArray can read it back.
     *
     * @return array<string, mixed>
     */
    private function toPayload(Pricing $pricing): array
    {
        return [
            'currency' => $pricing->currency,
            'rates' => $pricing->rates,
            'tokens' => [
                'characters_per_text_token' => $pricing->charactersPerTextToken,
                'text_token_variance' => $pricing->textTokenVariance,
                'per_reference_image' => $pricing->tokensPerReferenceImage,
                'output' => $pricing->outputTokens,
                'reasoning' => $pricing->reasoningTokens,
            ],
        ];
    }
}
