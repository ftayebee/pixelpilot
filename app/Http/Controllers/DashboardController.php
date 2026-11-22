<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\OverviewStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, OverviewStats $stats): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => $stats->forUser($request->user()),
        ]);
    }
}
