<?php

namespace App\Services\Generations;

use App\Models\Generation;
use App\Models\User;
use App\Services\Pricing\Estimate;
use App\Services\Pricing\PricingCatalog;
use App\Services\Pricing\PricingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records a new generation locally (with its estimate) before anything is
 * sent to Pixelforge, so there is always a row to attach webhooks,
 * retries and charges to.
 */
class GenerationCreator
{
    public function __construct(
        private readonly PricingCatalog $catalog,
        private readonly PricingService $pricing,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images: list<string>, client_request_id: string}  $input
     * @return array{generation: Generation, created: bool}
     */
    public function create(User $user, array $input): array
    {
        $existing = $this->findByClientRequest($user, $input['client_request_id']);
        if ($existing) {
            return ['generation' => $existing, 'created' => false];
        }

        $pricing = $this->catalog->current();
        $estimate = $this->pricing->estimate(
            mb_strlen($input['prompt']),
            $input['size'],
            $input['quality'],
            count($input['reference_images']),
            $pricing,
        );

        try {
            // Own transaction (a savepoint when nested) so a unique violation can be recovered from.
            $generation = DB::transaction(fn () => $user->generations()->create([
                'uuid' => (string) Str::uuid(),
                'idempotency_key' => (string) Str::uuid(),
                'client_request_id' => $input['client_request_id'],
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => array_values($input['reference_images']),
                ...$this->estimateAttributes($estimate),
            ]));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same client token won the race.
            return ['generation' => $this->findByClientRequest($user, $input['client_request_id']), 'created' => false];
        }

        return ['generation' => $generation, 'created' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function estimateAttributes(Estimate $estimate): array
    {
        return [
            'currency' => 'usd',
            'estimated_cost_min' => $estimate->costMin,
            'estimated_cost_max' => $estimate->costMax,
            'estimated_charge_min' => $estimate->chargeMin,
            'estimated_charge_max' => $estimate->chargeMax,
            'estimate_rates' => $estimate->rates,
            'estimated_at' => now(),
        ];
    }

    private function findByClientRequest(User $user, string $clientRequestId): ?Generation
    {
        return $user->generations()->where('client_request_id', $clientRequestId)->first();
    }
}
