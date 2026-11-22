<?php

namespace App\Services\Pixelforge\Data;

use UnexpectedValueException;

/**
 * GET /v1/pricing: per-1M-token rates plus the documented token-counting
 * parameters used to estimate a generation before it runs.
 */
final readonly class Pricing
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * @param  array<string, string>  $rates  decimal strings, USD per 1M tokens
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public string $currency,
        public array $rates,
        public int $charactersPerTextToken,
        public string $textTokenVariance,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rates = [];
        foreach (self::TOKEN_TYPES as $type) {
            $rate = $data['rates'][$type] ?? null;
            if (! is_string($rate) || ! preg_match('/^\d+(\.\d+)?$/', $rate)) {
                throw new UnexpectedValueException("Pricing is missing a valid rate for {$type}.");
            }
            $rates[$type] = $rate;
        }

        $tokens = $data['tokens'] ?? null;
        if (! is_array($tokens) || ! is_array($tokens['output'] ?? null) || ! is_array($tokens['reasoning'] ?? null)) {
            throw new UnexpectedValueException('Pricing is missing token parameters.');
        }

        $output = [];
        foreach ($tokens['output'] as $quality => $sizes) {
            foreach ((array) $sizes as $size => $count) {
                $output[(string) $quality][(string) $size] = (int) $count;
            }
        }

        $reasoning = [];
        foreach ($tokens['reasoning'] as $quality => $range) {
            $reasoning[(string) $quality] = ['min' => (int) ($range['min'] ?? 0), 'max' => (int) ($range['max'] ?? 0)];
        }

        return new self(
            currency: (string) ($data['currency'] ?? 'usd'),
            rates: $rates,
            charactersPerTextToken: max(1, (int) ($tokens['characters_per_text_token'] ?? 4)),
            // Kept as a decimal string (e.g. "0.15") so it never passes through float maths.
            textTokenVariance: self::decimalString($tokens['text_token_variance'] ?? '0'),
            tokensPerReferenceImage: (int) ($tokens['per_reference_image'] ?? 0),
            outputTokens: $output,
            reasoningTokens: $reasoning,
        );
    }

    /**
     * @return list<string>
     */
    public function sizes(): array
    {
        $quality = array_key_first($this->outputTokens);

        return $quality === null ? [] : array_keys($this->outputTokens[$quality]);
    }

    /**
     * @return list<string>
     */
    public function qualities(): array
    {
        return array_keys($this->outputTokens);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'rates' => $this->rates,
            'characters_per_text_token' => $this->charactersPerTextToken,
            'text_token_variance' => $this->textTokenVariance,
            'per_reference_image' => $this->tokensPerReferenceImage,
            'output' => $this->outputTokens,
            'reasoning' => $this->reasoningTokens,
        ];
    }

    private static function decimalString(mixed $value): string
    {
        // JSON numbers like 0.15 arrive as PHP floats; the shortest round-trip
        // representation ("0.15") is exactly what the docs publish.
        $string = is_float($value) ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') : (string) $value;

        if (! preg_match('/^\d+(\.\d+)?$/', $string)) {
            throw new UnexpectedValueException('Invalid text_token_variance in pricing.');
        }

        return $string;
    }
}
