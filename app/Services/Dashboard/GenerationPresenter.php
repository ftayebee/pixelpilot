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
            'actual_charge' => $generation->actual_charge === null ? null : Money::normalize($generation->actual_charge),
            'created_at' => $generation->created_at?->toIso8601ZuluString(),
            'completed_at' => $generation->completed_at?->toIso8601ZuluString(),
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
}
