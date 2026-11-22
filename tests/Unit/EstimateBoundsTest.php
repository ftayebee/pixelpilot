<?php

namespace Tests\Unit;

use App\Services\Pixelforge\Data\Pricing;
use App\Services\Pricing\PricingService;
use PHPUnit\Framework\TestCase;
use Tests\Support\PixelforgeFixtures as F;

/**
 * Property check: for any usage Pixelforge can report within its documented
 * bounds, the actual charge lands inside the estimate range we showed.
 * Deterministic (seeded) so failures are reproducible.
 */
class EstimateBoundsTest extends TestCase
{
    public function test_actual_charge_is_always_within_the_estimate(): void
    {
        $service = new PricingService('1.25');
        $pricing = Pricing::fromArray(F::pricing());
        mt_srand(20261122);

        for ($i = 0; $i < 2000; $i++) {
            $quality = $pricing->qualities()[mt_rand(0, 2)];
            $size = $pricing->sizes()[mt_rand(0, 2)];
            $characters = mt_rand(1, 4000);
            $references = mt_rand(0, 4);

            $estimate = $service->estimate($characters, $size, $quality, $references, $pricing);

            $text = $estimate->tokens['input_text_tokens'];
            $reasoning = $estimate->tokens['reasoning_tokens'];
            $usage = [
                'input_text_tokens' => mt_rand($text['min'], $text['max']),
                'input_image_tokens' => $references * 765,
                'output_tokens' => $pricing->outputTokens[$quality][$size],
                'reasoning_tokens' => mt_rand($reasoning['min'], $reasoning['max']),
            ];

            $charge = $service->charge($service->cost($usage, $pricing->rates));

            $this->assertGreaterThanOrEqual(0, bccomp($charge, $estimate->chargeMin, 12), "Below estimate on iteration {$i}");
            $this->assertLessThanOrEqual(0, bccomp($charge, $estimate->chargeMax, 12), "Above estimate on iteration {$i}");
        }
    }

    public function test_text_range_contains_every_count_within_15_percent(): void
    {
        $service = new PricingService('1.25');
        $pricing = Pricing::fromArray(F::pricing());

        for ($characters = 0; $characters <= 4000; $characters++) {
            $range = $service->textTokenRange($characters, $pricing);
            $estimated = (int) ceil($characters / 4);

            // Exact integer bounds of ±15%: floor(est × 85 / 100) and ceil(est × 115 / 100).
            $this->assertSame(intdiv($estimated * 85, 100), $range['min'], "min at {$characters}");
            $this->assertSame(intdiv($estimated * 115 + 99, 100), $range['max'], "max at {$characters}");
            $this->assertSame($estimated, $range['estimated']);
        }
    }
}
