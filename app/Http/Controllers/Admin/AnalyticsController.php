<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\ProviderUsageSync;
use App\Services\Admin\UsageReport;
use App\Services\Pixelforge\PixelforgeException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public const MAX_RANGE_DAYS = 366;

    public function index(Request $request, UsageReport $report): Response
    {
        [$start, $end, $userId] = $this->filters($request);

        return Inertia::render('admin/Analytics', [
            'filters' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'user' => $userId],
            'report' => $report->build($start, $end, $userId),
            // Only what the filter needs: id, name, email of users who have generated anything.
            'users' => User::query()->whereHas('generations')->orderBy('name')->get(['id', 'name', 'email']),
            'pixelforgeConfigured' => filled(config('pixelforge.api_key')),
        ]);
    }

    public function sync(Request $request, ProviderUsageSync $sync): RedirectResponse
    {
        [$start, $end] = $this->filters($request);

        try {
            $days = $sync->sync($start, $end);
        } catch (PixelforgeException $e) {
            return back()->with('error', 'Could not fetch usage from Pixelforge: '.$e->getMessage());
        }

        return back()->with('success', "Synced {$days} day(s) of Pixelforge usage.");
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int|null}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
            'user' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $end = isset($validated['end']) ? CarbonImmutable::parse($validated['end'], 'UTC') : $today;
        $start = isset($validated['start']) ? CarbonImmutable::parse($validated['start'], 'UTC') : $end->subDays(29);

        if ($start->diffInDays($end) >= self::MAX_RANGE_DAYS) {
            $start = $end->subDays(self::MAX_RANGE_DAYS - 1);
        }

        return [$start, $end, isset($validated['user']) ? (int) $validated['user'] : null];
    }
}
