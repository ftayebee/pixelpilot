<?php

namespace App\Services\Admin;

use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Copies Pixelforge's per-day usage (GET /v1/usage) for the configured API
 * key into provider_usage_days. Safe to run repeatedly: days are upserted,
 * and if Pixelforge reports a different amount for a day we already have,
 * the old value is kept in previous_cost_usd and the change is logged.
 */
class ProviderUsageSync
{
    /** Pixelforge's maximum range per request. */
    public const MAX_DAYS_PER_REQUEST = 92;

    public function __construct(private readonly PixelforgeClient $client) {}

    public static function keyFingerprint(): string
    {
        return substr(hash('sha256', (string) config('pixelforge.api_key')), 0, 16);
    }

    /**
     * @return int number of days stored
     */
    public function sync(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $fingerprint = self::keyFingerprint();
        $stored = 0;

        for ($chunkStart = $start; $chunkStart->lte($end); $chunkStart = $chunkStart->addDays(self::MAX_DAYS_PER_REQUEST)) {
            $chunkEnd = $chunkStart->addDays(self::MAX_DAYS_PER_REQUEST - 1)->min($end);

            $usage = $this->client->usage($chunkStart->toDateString(), $chunkEnd->toDateString());

            foreach ($usage['data'] as $day) {
                $this->store($fingerprint, $day);
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function store(string $fingerprint, array $day): void
    {
        $cost = Money::normalize((string) $day['cost_usd']);

        DB::transaction(function () use ($fingerprint, $day, $cost) {
            $existing = DB::table('provider_usage_days')
                ->where('key_fingerprint', $fingerprint)
                ->where('date', $day['date'])
                ->lockForUpdate()
                ->first();

            $values = [
                'generations' => (int) $day['generations'],
                'input_text_tokens' => (int) $day['input_text_tokens'],
                'input_image_tokens' => (int) $day['input_image_tokens'],
                'output_tokens' => (int) $day['output_tokens'],
                'reasoning_tokens' => (int) $day['reasoning_tokens'],
                'cost_usd' => $cost,
                'synced_at' => now(),
                'updated_at' => now(),
            ];

            if ($existing === null) {
                DB::table('provider_usage_days')->insert([
                    'key_fingerprint' => $fingerprint,
                    'date' => $day['date'],
                    'created_at' => now(),
                    ...$values,
                ]);

                return;
            }

            if (Money::compare($existing->cost_usd, $cost) !== 0) {
                $values['previous_cost_usd'] = $existing->cost_usd;
                Log::warning('Pixelforge usage changed for a day already synced.', [
                    'date' => $day['date'],
                    'before' => $existing->cost_usd,
                    'after' => $cost,
                ]);
            }

            DB::table('provider_usage_days')->where('id', $existing->id)->update($values);
        });
    }
}
