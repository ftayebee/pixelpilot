<?php

namespace App\Services\Dashboard;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Real, per-user numbers for the overview page. Money totals are summed by
 * PostgreSQL on NUMERIC columns, so they stay exact.
 */
class OverviewStats
{
    public const DAYS = 14;

    public function __construct(private readonly GenerationPresenter $presenter) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $totals = DB::table('generations')
            ->where('user_id', $user->id)
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where status = 'succeeded') as succeeded")
            ->selectRaw("count(*) filter (where status = 'failed') as failed")
            ->selectRaw("count(*) filter (where status in ('submitting','queued','processing')) as pending")
            ->selectRaw('coalesce(sum(actual_charge), 0) as spent')
            ->first();

        $finished = (int) $totals->succeeded + (int) $totals->failed;

        return [
            'totals' => [
                'total' => (int) $totals->total,
                'succeeded' => (int) $totals->succeeded,
                'failed' => (int) $totals->failed,
                'pending' => (int) $totals->pending,
                'spent' => Money::normalize((string) $totals->spent),
                // Success rate over finished generations only; in-flight ones haven't had a chance to fail.
                'success_rate' => $finished > 0 ? round((int) $totals->succeeded / $finished * 100, 1) : null,
            ],
            'daily' => $this->daily($user),
            'recent' => $user->generations()
                ->latest()
                ->limit(6)
                ->get()
                ->map(fn (Generation $g) => $this->presenter->summary($g))
                ->all(),
        ];
    }

    /**
     * Activity by the UTC day a generation was created, and spending by the
     * UTC day it completed (the same day Pixelforge bills it on).
     *
     * @return list<array{date: string, succeeded: int, failed: int, pending: int, spent: string}>
     */
    private function daily(User $user): array
    {
        $end = CarbonImmutable::now('UTC')->startOfDay();
        $start = $end->subDays(self::DAYS - 1);

        $activity = DB::table('generations')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $start)
            ->selectRaw("to_char(created_at at time zone 'UTC', 'YYYY-MM-DD') as day")
            ->selectRaw("count(*) filter (where status = 'succeeded') as succeeded")
            ->selectRaw("count(*) filter (where status = 'failed') as failed")
            ->selectRaw("count(*) filter (where status in ('submitting','queued','processing')) as pending")
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $spending = DB::table('generations')
            ->where('user_id', $user->id)
            ->where('status', GenerationStatus::Succeeded->value)
            ->where('completed_at', '>=', $start)
            ->selectRaw("to_char(completed_at at time zone 'UTC', 'YYYY-MM-DD') as day")
            ->selectRaw('sum(actual_charge) as spent')
            ->groupBy('day')
            ->pluck('spent', 'day');

        $days = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $row = $activity->get($key);
            $days[] = [
                'date' => $key,
                'succeeded' => (int) ($row->succeeded ?? 0),
                'failed' => (int) ($row->failed ?? 0),
                'pending' => (int) ($row->pending ?? 0),
                'spent' => Money::normalize((string) ($spending[$key] ?? '0')),
            ];
        }

        return $days;
    }
}
