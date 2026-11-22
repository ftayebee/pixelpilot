<?php

namespace App\Services\Dashboard;

use App\Models\Generation;
use App\Support\Money;

/**
 * Shapes a generation for the frontend. Money leaves the server as exact
 * decimal strings; the browser only formats them for display.
 */
class GenerationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Generation $generation): array
    {
        return [
            'id' => $generation->uuid,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'status' => $generation->status->value,
            'image_url' => $this->imageUrl($generation),
            'estimated_charge_min' => Money::normalize($generation->estimated_charge_min),
            'estimated_charge_max' => Money::normalize($generation->estimated_charge_max),
            'actual_charge' => $this->money($generation->actual_charge),
            'created_at' => $generation->created_at?->toIso8601ZuluString(),
            'completed_at' => $generation->completed_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Generation $generation): array
    {
        return [
            ...$this->summary($generation),
            'reference_images' => $generation->reference_images ?? [],
            'provider_id' => $generation->provider_generation_id,
            'image_url_expires_at' => $generation->image_url_expires_at?->toIso8601ZuluString(),
            'image_expired' => $generation->image_url !== null && $this->imageUrl($generation) === null,
            'error' => $generation->error_code ? [
                'code' => $generation->error_code,
                'message' => $generation->error_message,
            ] : null,
            'estimate' => [
                'cost_min' => Money::normalize($generation->estimated_cost_min),
                'cost_max' => Money::normalize($generation->estimated_cost_max),
                'charge_min' => Money::normalize($generation->estimated_charge_min),
                'charge_max' => Money::normalize($generation->estimated_charge_max),
                'rates' => $generation->estimate_rates,
            ],
            'usage' => $generation->input_text_tokens === null ? null : [
                'input_text_tokens' => $generation->input_text_tokens,
                'input_image_tokens' => $generation->input_image_tokens,
                'output_tokens' => $generation->output_tokens,
                'reasoning_tokens' => $generation->reasoning_tokens,
            ],
            'rates' => $generation->rates,
            'actual_cost' => $this->money($generation->actual_cost),
            'started_at' => $generation->started_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * The provider URL expires 24h after completion; after that the frontend
     * shows a fallback instead of a broken image.
     */
    public function imageUrl(Generation $generation): ?string
    {
        if ($generation->image_url && $generation->image_url_expires_at?->isFuture()) {
            return $generation->image_url;
        }

        return null;
    }

    private function money(?string $amount): ?string
    {
        return $amount === null ? null : Money::normalize($amount);
    }
}
