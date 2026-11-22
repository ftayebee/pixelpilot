<?php

namespace Tests\Unit;

use App\Services\Pixelforge\Data\Pricing;
use App\Services\Pricing\PricingService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PixelforgeFixtures as F;

/**
 * Every expected value here was worked out by hand from the documented
 * formula and is compared as an exact string.
 */
class PricingServiceTest extends TestCase
{
    private PricingService $service;

    private Pricing $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PricingService('1.25');
        $this->pricing = Pricing::fromArray(F::pricing());
    }

    private function usage(int $text, int $image, int $output, int $reasoning): array
    {
        return [
            'input_text_tokens' => $text,
            'input_image_tokens' => $image,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning,
        ];
    }

    public function test_documented_succeeded_example(): void
    {
        // 12×5 + 765×10 + 6450×40 + 3184×12.5 = 305,510 → $0.30551
        $cost = $this->service->cost($this->usage(12, 765, 6450, 3184), F::RATES);

        $this->assertSame('0.305510000000', $cost);
        $this->assertSame('0.381887500000', $this->service->charge($cost));
    }

    public function test_documented_usage_day_matches_pixelforge(): void
    {
        // From the /v1/usage example: 41 text, 0 image, 3300 output, 2950 reasoning → "0.169080000"
        $this->assertSame('0.169080000000', $this->service->cost($this->usage(41, 0, 3300, 2950), F::RATES));
    }

    public function test_documented_worst_case_estimate(): void
    {
        $estimate = $this->service->estimate(400, '1536x1024', 'high', 2, $this->pricing);

        $this->assertSame(['min' => 85, 'max' => 115, 'estimated' => 100], $estimate->tokens['input_text_tokens']);
        $this->assertSame('0.373875000000', $estimate->costMax);
        $this->assertSame('0.467343750000', $estimate->chargeMax);
        // min: 85×5 + 1530×10 + 6450×40 + 1000×12.5 = 286,225
        $this->assertSame('0.286225000000', $estimate->costMin);
        $this->assertSame('0.357781250000', $estimate->chargeMin);
    }

    public function test_text_only_estimate(): void
    {
        // 100 chars → 25 tokens (21..29); low 1024x1024 → 280 output; reasoning 0..500
        $estimate = $this->service->estimate(100, '1024x1024', 'low', 0, $this->pricing);

        $this->assertSame(['min' => 0, 'max' => 0], $estimate->tokens['input_image_tokens']);
        // min: 21×5 + 280×40 = 11,305 ; max: 29×5 + 280×40 + 500×12.5 = 17,595
        $this->assertSame('0.011305000000', $estimate->costMin);
        $this->assertSame('0.017595000000', $estimate->costMax);
        $this->assertSame('0.014131250000', $estimate->chargeMin);
        $this->assertSame('0.021993750000', $estimate->chargeMax);
    }

    public function test_one_reference_image_adds_exactly_765_tokens(): void
    {
        $without = $this->service->estimate(40, '1024x1024', 'medium', 0, $this->pricing);
        $with = $this->service->estimate(40, '1024x1024', 'medium', 1, $this->pricing);

        $this->assertSame(['min' => 765, 'max' => 765], $with->tokens['input_image_tokens']);
        // 765 × $10 / 1M = $0.00765
        $this->assertSame('0.007650000000', bcsub($with->costMin, $without->costMin, 12));
        $this->assertSame('0.007650000000', bcsub($with->costMax, $without->costMax, 12));
    }

    public function test_four_reference_images(): void
    {
        $estimate = $this->service->estimate(40, '1024x1024', 'medium', 4, $this->pricing);

        $this->assertSame(['min' => 3060, 'max' => 3060], $estimate->tokens['input_image_tokens']);
        // min: 8×5 + 3060×10 + 1100×40 + 200×12.5 = 77,140 ; max: 12×5 + 30,600 + 44,000 + 25,000 = 99,660
        $this->assertSame('0.077140000000', $estimate->costMin);
        $this->assertSame('0.099660000000', $estimate->costMax);
    }

    /**
     * @return array<string, array{string, string, int, int, int}>
     */
    public static function sizeQualityMatrix(): array
    {
        return [
            'low square' => ['low', '1024x1024', 280, 0, 500],
            'low landscape' => ['low', '1536x1024', 420, 0, 500],
            'low portrait' => ['low', '1024x1536', 420, 0, 500],
            'medium square' => ['medium', '1024x1024', 1100, 200, 2000],
            'medium landscape' => ['medium', '1536x1024', 1650, 200, 2000],
            'medium portrait' => ['medium', '1024x1536', 1650, 200, 2000],
            'high square' => ['high', '1024x1024', 4300, 1000, 8000],
            'high landscape' => ['high', '1536x1024', 6450, 1000, 8000],
            'high portrait' => ['high', '1024x1536', 6450, 1000, 8000],
        ];
    }

    #[DataProvider('sizeQualityMatrix')]
    public function test_output_and_reasoning_tokens_follow_the_documented_table(string $quality, string $size, int $output, int $reasoningMin, int $reasoningMax): void
    {
        $estimate = $this->service->estimate(0, $size, $quality, 0, $this->pricing);

        $this->assertSame(['min' => $output, 'max' => $output], $estimate->tokens['output_tokens']);
        $this->assertSame(['min' => $reasoningMin, 'max' => $reasoningMax], $estimate->tokens['reasoning_tokens']);

        $expectedMin = bcdiv((string) ($output * 40 + $reasoningMin * 12.5), '1000000', 12);
        $expectedMax = bcdiv((string) ($output * 40 + $reasoningMax * 12.5), '1000000', 12);
        $this->assertSame($expectedMin, $estimate->costMin);
        $this->assertSame($expectedMax, $estimate->costMax);
    }

    public function test_zero_tokens_cost_nothing(): void
    {
        $cost = $this->service->cost($this->usage(0, 0, 0, 0), F::RATES);

        $this->assertSame('0.000000000000', $cost);
        $this->assertSame('0.000000000000', $this->service->charge($cost));
    }

    public function test_large_token_counts_stay_exact(): void
    {
        // 1e9 output tokens × $40/1M = $40,000; 999,999,999 reasoning × $12.50/1M = $12,499.9999875
        $cost = $this->service->cost($this->usage(0, 0, 1_000_000_000, 999_999_999), F::RATES);

        $this->assertSame('52499.999987500000', $cost);
        $this->assertSame('65624.999984375000', $this->service->charge($cost));
    }

    public function test_fractional_cent_costs_are_not_rounded(): void
    {
        // One text token: $0.000005, charged $0.00000625
        $cost = $this->service->cost($this->usage(1, 0, 0, 0), F::RATES);
        $this->assertSame('0.000005000000', $cost);
        $this->assertSame('0.000006250000', $this->service->charge($cost));

        // Three reasoning tokens: $0.0000375 → $0.000046875 (nine places)
        $cost = $this->service->cost($this->usage(0, 0, 0, 3), F::RATES);
        $this->assertSame('0.000037500000', $cost);
        $this->assertSame('0.000046875000', $this->service->charge($cost));
    }

    public function test_markup_is_exactly_25_percent(): void
    {
        foreach (['0.305510000000', '0.000001000000', '123.456789012000', '0.000000001000'] as $cost) {
            $charge = $this->service->charge($cost);
            $this->assertSame(0, bccomp($charge, bcmul($cost, '1.25', 14), 14), $cost);
            $this->assertSame(0, bccomp(bcsub($charge, $cost, 14), bcmul($cost, '0.25', 14), 14), $cost);
        }
    }

    public function test_billing_uses_the_rates_given_not_hardcoded_ones(): void
    {
        $rates = ['input_text_tokens' => '6.00', 'input_image_tokens' => '10.00', 'output_tokens' => '42.50', 'reasoning_tokens' => '12.75'];

        // 10×6 + 0 + 100×42.5 + 10×12.75 = 4,437.5
        $this->assertSame('0.004437500000', $this->service->cost($this->usage(10, 0, 100, 10), $rates));
    }

    public function test_text_token_range_rounds_outwards(): void
    {
        $this->assertSame(['min' => 0, 'max' => 0, 'estimated' => 0], $this->service->textTokenRange(0, $this->pricing));
        $this->assertSame(['min' => 0, 'max' => 2, 'estimated' => 1], $this->service->textTokenRange(1, $this->pricing));
        $this->assertSame(['min' => 0, 'max' => 2, 'estimated' => 1], $this->service->textTokenRange(4, $this->pricing));
        $this->assertSame(['min' => 1, 'max' => 3, 'estimated' => 2], $this->service->textTokenRange(5, $this->pricing));
        // 20 × 0.85 = 17 and 20 × 1.15 = 23 exactly; no extra token on exact boundaries
        $this->assertSame(['min' => 17, 'max' => 23, 'estimated' => 20], $this->service->textTokenRange(80, $this->pricing));
        $this->assertSame(['min' => 850, 'max' => 1150, 'estimated' => 1000], $this->service->textTokenRange(4000, $this->pricing));
    }

    public function test_invalid_inputs_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->estimate(10, '512x512', 'medium', 0, $this->pricing);
    }

    public function test_missing_rate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->cost($this->usage(1, 1, 1, 1), ['input_text_tokens' => '5.00']);
    }
}
