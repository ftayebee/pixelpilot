<?php

namespace App\Services\Pricing;

use App\Models\Generation;
use App\Models\GenerationCharge;
use App\Services\Pixelforge\Data\ProviderGeneration;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Turns a succeeded generation's usage into its one and only charge.
 *
 * Must run inside the transaction that holds the generation's row lock. The
 * unique index on generation_charges.generation_id is the backstop: even if
 * two processes somehow got here for the same generation, the second insert
 * fails and its transaction rolls back.
 */
class ChargeRecorder
{
    public function __construct(private readonly PricingService $pricing) {}

    public function record(Generation $generation, ProviderGeneration $snapshot): GenerationCharge
    {
        if ($snapshot->usage === null) {
            throw new LogicException("Cannot charge {$snapshot->id} without usage.");
        }

        // Bill at the rates Pixelforge fixed on this generation, not today's.
        $rates = $snapshot->rates !== [] ? $snapshot->rates : (array) $generation->rates;
        $usage = [
            'input_text_tokens' => $snapshot->usage['input_text_tokens'],
            'input_image_tokens' => $snapshot->usage['input_image_tokens'],
            'output_tokens' => $snapshot->usage['output_tokens'],
            'reasoning_tokens' => $snapshot->usage['reasoning_tokens'],
        ];

        $cost = $this->pricing->cost($usage, $rates);
        $charge = $this->pricing->charge($cost);
        $completedAt = $snapshot->completedAt ?? CarbonImmutable::now('UTC');

        $generation->forceFill([
            ...$usage,
            'rates' => $rates,
            'actual_cost' => $cost,
            'actual_charge' => $charge,
            'charged_at' => now(),
        ]);

        return GenerationCharge::create([
            'generation_id' => $generation->id,
            'user_id' => $generation->user_id,
            'provider_generation_id' => $snapshot->id,
            ...$usage,
            'rate_input_text' => $rates['input_text_tokens'],
            'rate_input_image' => $rates['input_image_tokens'],
            'rate_output' => $rates['output_tokens'],
            'rate_reasoning' => $rates['reasoning_tokens'],
            'markup' => $this->pricing->markup(),
            'provider_cost' => $cost,
            'customer_charge' => $charge,
            'currency' => 'usd',
            'billed_on' => $completedAt->utc()->toDateString(),
        ]);
    }
}
