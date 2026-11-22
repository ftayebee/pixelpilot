<?php

namespace App\Services\Pricing;

use App\Services\Pixelforge\Data\Pricing;
use App\Support\Money;
use InvalidArgumentException;

/**
 * The single source of pricing truth.
 *
 *   provider cost   = Σ (tokens × rate per 1M) / 1,000,000
 *   customer charge = provider cost × markup (1.25)
 *
 * All maths is BCMath on decimal strings; nothing is rounded. See
 * docs/pricing.md for the estimate rules and precision policy.
 */
class PricingService
{
    public function __construct(private readonly string $markup = '1.25') {}

    public function markup(): string
    {
        return $this->markup;
    }

    /**
     * Price range for a generation before it runs, following the documented
     * token rules:
     *  - text: ceil(chars / 4), real count within ±15% of that
     *  - images: exactly 765 per reference
     *  - output: exact for the quality and size
     *  - reasoning: the quality's documented min..max
     */
    public function estimate(int $promptCharacters, string $size, string $quality, int $referenceCount, Pricing $pricing): Estimate
    {
        if (! isset($pricing->outputTokens[$quality][$size], $pricing->reasoningTokens[$quality])) {
            throw new InvalidArgumentException("Unsupported size/quality: {$size} / {$quality}.");
        }

        $text = $this->textTokenRange($promptCharacters, $pricing);
        $images = $referenceCount * $pricing->tokensPerReferenceImage;
        $output = $pricing->outputTokens[$quality][$size];
        $reasoning = $pricing->reasoningTokens[$quality];

        $tokens = [
            'input_text_tokens' => $text,
            'input_image_tokens' => ['min' => $images, 'max' => $images],
            'output_tokens' => ['min' => $output, 'max' => $output],
            'reasoning_tokens' => ['min' => $reasoning['min'], 'max' => $reasoning['max']],
        ];

        $costMin = $this->cost(array_map(fn ($r) => $r['min'], $tokens), $pricing->rates);
        $costMax = $this->cost(array_map(fn ($r) => $r['max'], $tokens), $pricing->rates);

        return new Estimate(
            tokens: $tokens,
            rates: $pricing->rates,
            costMin: $costMin,
            costMax: $costMax,
            chargeMin: $this->charge($costMin),
            chargeMax: $this->charge($costMax),
            markup: $this->markup,
        );
    }

    /**
     * Exact provider cost for a token usage at the given per-1M rates.
     *
     * @param  array<string, int>  $usage  keyed by token type
     * @param  array<string, string>  $rates  keyed by token type
     */
    public function cost(array $usage, array $rates): string
    {
        $total = '0';

        foreach (Pricing::TOKEN_TYPES as $type) {
            $tokens = $usage[$type] ?? 0;
            $rate = $rates[$type] ?? null;

            if (! is_int($tokens) || $tokens < 0) {
                throw new InvalidArgumentException("Invalid token count for {$type}.");
            }

            if ($rate === null) {
                throw new InvalidArgumentException("Missing rate for {$type}.");
            }

            $total = Money::add($total, Money::mul((string) $tokens, $rate));
        }

        return Money::normalize(Money::div($total, '1000000'));
    }

    public function charge(string $providerCost): string
    {
        return Money::normalize(Money::mul($providerCost, $this->markup));
    }

    /**
     * @return array{min: int, max: int, estimated: int}
     */
    public function textTokenRange(int $characters, Pricing $pricing): array
    {
        $estimated = intdiv($characters + $pricing->charactersPerTextToken - 1, $pricing->charactersPerTextToken);

        $low = Money::mul((string) $estimated, Money::sub('1', $pricing->textTokenVariance));
        $high = Money::mul((string) $estimated, Money::add('1', $pricing->textTokenVariance));

        return [
            'min' => $this->floor($low),
            'max' => $this->ceil($high),
            'estimated' => $estimated,
        ];
    }

    private function floor(string $value): int
    {
        return (int) explode('.', $value)[0];
    }

    private function ceil(string $value): int
    {
        $whole = $this->floor($value);

        return Money::compare($value, (string) $whole) > 0 ? $whole + 1 : $whole;
    }
}
