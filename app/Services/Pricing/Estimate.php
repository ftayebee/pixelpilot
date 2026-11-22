<?php

namespace App\Services\Pricing;

/**
 * Up-front price range for a generation. Every amount is an exact decimal
 * string. Token ranges are inclusive.
 */
final readonly class Estimate
{
    /**
     * @param  array<string, array{min: int, max: int}>  $tokens  per token type
     * @param  array<string, string>  $rates  USD per 1M tokens, as used
     */
    public function __construct(
        public array $tokens,
        public array $rates,
        public string $costMin,
        public string $costMax,
        public string $chargeMin,
        public string $chargeMax,
        public string $markup,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tokens' => $this->tokens,
            'rates' => $this->rates,
            'cost_min' => $this->costMin,
            'cost_max' => $this->costMax,
            'charge_min' => $this->chargeMin,
            'charge_max' => $this->chargeMax,
            'markup' => $this->markup,
        ];
    }
}
