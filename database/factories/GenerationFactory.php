<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Amounts below are worked out by hand for a medium 1024x1024 image with a
 * 40-character prompt at the documented rates, so tests can assert on them.
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'provider_generation_id' => 'gen_'.Str::upper(Str::random(26)),
            'status' => GenerationStatus::Queued,
            'prompt' => 'A lighthouse on a cliff at dawn, oil art',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            // text 8..12 tokens, output 1100, reasoning 200..2000
            'estimated_cost_min' => '0.046540000000',
            'estimated_cost_max' => '0.069060000000',
            'estimated_charge_min' => '0.058175000000',
            'estimated_charge_max' => '0.086325000000',
            'estimate_rates' => self::RATES,
            'estimated_at' => now(),
            'rates' => self::RATES,
            'provider_created_at' => now(),
            'submit_attempts' => 1,
        ];
    }

    public function submitting(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Submitting,
            'provider_generation_id' => null,
            'rates' => null,
            'provider_created_at' => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Succeeded,
            'input_text_tokens' => 12,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 800,
            // (12×5 + 1100×40 + 800×12.5) / 1e6 = 0.05406; × 1.25 = 0.0675750
            'actual_cost' => '0.054060000000',
            'actual_charge' => '0.067575000000',
            'charged_at' => now(),
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png',
            'image_url_expires_at' => now()->addDay(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Failed,
            'actual_cost' => '0',
            'actual_charge' => '0',
            'error_code' => 'generation_failed',
            'error_message' => "The model couldn't produce an image for this prompt.",
            'completed_at' => now(),
        ]);
    }
}
