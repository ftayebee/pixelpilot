<?php

namespace App\Services\Admin;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Operator report: what users were charged, what Pixelforge charged us and
 * the difference, per UTC day and per user.
 *
 * Money comes from generation_charges (one immutable row per billed
 * generation) and is summed by PostgreSQL on NUMERIC columns. Days are the
 * UTC day a generation completed, which is also the day Pixelforge bills
 * it, so our provider cost per day is directly comparable to /v1/usage.
 */
class UsageReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $start, CarbonImmutable $end, ?int $userId): array
    {
        $daily = $this->daily($start, $end, $userId);

        return [
            'summary' => $this->summary($daily),
            'daily' => $daily,
            'users' => $this->perUser($start, $end, $userId),
            // Pixelforge reports usage per key, not per user, so reconciliation is only meaningful unfiltered.
            'reconciliation' => $userId === null ? $this->reconciliation($start, $end, $daily) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function daily(CarbonImmutable $start, CarbonImmutable $end, ?int $userId): array
    {
        $money = $this->charges($start, $end, $userId)
            ->selectRaw("to_char(billed_on, 'YYYY-MM-DD') as day")
            ->selectRaw('count(*) as billed')
            ->selectRaw('sum(customer_charge) as charges, sum(provider_cost) as costs, sum(customer_charge) - sum(provider_cost) as profit')
            ->groupBy('billed_on')
            ->get()
            ->keyBy('day');

        $counts = $this->finished($start, $end, $userId)
            ->selectRaw("to_char(completed_at at time zone 'UTC', 'YYYY-MM-DD') as day")
            ->selectRaw("count(*) filter (where status = 'succeeded') as succeeded")
            ->selectRaw("count(*) filter (where status = 'failed') as failed")
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $days = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $m = $money->get($key);
            $c = $counts->get($key);
            $succeeded = (int) ($c->succeeded ?? 0);
            $failed = (int) ($c->failed ?? 0);

            $days[] = [
                'date' => $key,
                'generations' => $succeeded + $failed,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'charges' => Money::normalize((string) ($m->charges ?? '0')),
                'costs' => Money::normalize((string) ($m->costs ?? '0')),
                'profit' => Money::normalize((string) ($m->profit ?? '0')),
            ];
        }

        return $days;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function perUser(CarbonImmutable $start, CarbonImmutable $end, ?int $userId): array
    {
        $money = $this->charges($start, $end, $userId)
            ->selectRaw('user_id, sum(customer_charge) as charges, sum(provider_cost) as costs, sum(customer_charge) - sum(provider_cost) as profit')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $counts = $this->finished($start, $end, $userId)
            ->selectRaw("user_id, count(*) filter (where status = 'succeeded') as succeeded, count(*) filter (where status = 'failed') as failed")
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $userIds = $money->keys()->merge($counts->keys())->unique()->values();
        $users = DB::table('users')->whereIn('id', $userIds)->get(['id', 'name', 'email'])->keyBy('id');

        return $userIds
            ->map(function ($id) use ($money, $counts, $users) {
                $m = $money->get($id);
                $c = $counts->get($id);

                return [
                    'user_id' => (int) $id,
                    'name' => $users->get($id)?->name,
                    'email' => $users->get($id)?->email,
                    'generations' => (int) ($c->succeeded ?? 0) + (int) ($c->failed ?? 0),
                    'succeeded' => (int) ($c->succeeded ?? 0),
                    'failed' => (int) ($c->failed ?? 0),
                    'charges' => Money::normalize((string) ($m->charges ?? '0')),
                    'costs' => Money::normalize((string) ($m->costs ?? '0')),
                    'profit' => Money::normalize((string) ($m->profit ?? '0')),
                ];
            })
            ->sort(fn ($a, $b) => Money::compare($b['charges'], $a['charges']))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $daily
     * @return array<string, mixed>
     */
    private function summary(array $daily): array
    {
        $charges = Money::add('0', ...array_column($daily, 'charges'));
        $costs = Money::add('0', ...array_column($daily, 'costs'));

        return [
            'charges' => Money::normalize($charges),
            'costs' => Money::normalize($costs),
            'profit' => Money::normalize(Money::sub($charges, $costs)),
            'generations' => array_sum(array_column($daily, 'generations')),
            'succeeded' => array_sum(array_column($daily, 'succeeded')),
            'failed' => array_sum(array_column($daily, 'failed')),
        ];
    }

    /**
     * Compares our provider cost per day with what Pixelforge reported for
     * this API key. Any difference is shown, never hidden.
     *
     * @param  list<array<string, mixed>>  $daily
     * @return array<string, mixed>
     */
    private function reconciliation(CarbonImmutable $start, CarbonImmutable $end, array $daily): array
    {
        $provider = DB::table('provider_usage_days')
            ->where('key_fingerprint', ProviderUsageSync::keyFingerprint())
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->date, 0, 10));

        $ours = collect($daily)->keyBy('date');
        $billedCounts = $this->charges($start, $end, null)
            ->selectRaw("to_char(billed_on, 'YYYY-MM-DD') as day, count(*) as billed")
            ->groupBy('billed_on')
            ->pluck('billed', 'day');

        $rows = [];
        $mismatches = 0;
        $missing = 0;

        foreach ($ours as $date => $day) {
            $theirs = $provider->get($date);

            if ($theirs === null) {
                $missing++;
                $rows[] = ['date' => $date, 'ours' => $day['costs'], 'pixelforge' => null, 'difference' => null,
                    'our_generations' => (int) ($billedCounts[$date] ?? 0), 'pixelforge_generations' => null, 'status' => 'not_synced', 'previous' => null];

                continue;
            }

            $theirCost = Money::normalize((string) $theirs->cost_usd);
            $difference = Money::normalize(Money::sub($day['costs'], $theirCost));
            $countsMatch = (int) ($billedCounts[$date] ?? 0) === (int) $theirs->generations;
            $matches = Money::isZero($difference) && $countsMatch;
            $mismatches += $matches ? 0 : 1;

            $rows[] = [
                'date' => $date,
                'ours' => $day['costs'],
                'pixelforge' => $theirCost,
                'difference' => $difference,
                'our_generations' => (int) ($billedCounts[$date] ?? 0),
                'pixelforge_generations' => (int) $theirs->generations,
                'status' => $matches ? 'match' : 'mismatch',
                'previous' => $theirs->previous_cost_usd !== null ? Money::normalize((string) $theirs->previous_cost_usd) : null,
            ];
        }

        $lastSynced = $provider->max('synced_at');

        return [
            'status' => $mismatches > 0 ? 'mismatch' : ($missing > 0 ? 'incomplete' : 'match'),
            'mismatches' => $mismatches,
            'not_synced' => $missing,
            'last_synced_at' => $lastSynced ? CarbonImmutable::parse($lastSynced)->toIso8601ZuluString() : null,
            'key_fingerprint' => ProviderUsageSync::keyFingerprint(),
            'pixelforge_total' => Money::normalize(Money::add('0', ...array_map(fn ($r) => (string) $r->cost_usd, $provider->values()->all()))),
            'days' => array_values(array_reverse($rows)),
        ];
    }

    private function charges(CarbonImmutable $start, CarbonImmutable $end, ?int $userId): Builder
    {
        return DB::table('generation_charges')
            ->whereBetween('billed_on', [$start->toDateString(), $end->toDateString()])
            ->when($userId, fn (Builder $q) => $q->where('user_id', $userId));
    }

    private function finished(CarbonImmutable $start, CarbonImmutable $end, ?int $userId): Builder
    {
        return DB::table('generations')
            ->whereIn('status', ['succeeded', 'failed'])
            ->where('completed_at', '>=', $start->startOfDay())
            ->where('completed_at', '<', $end->addDay()->startOfDay())
            ->when($userId, fn (Builder $q) => $q->where('user_id', $userId));
    }
}
