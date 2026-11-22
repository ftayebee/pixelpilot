<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_sees_empty_stats(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('stats.totals.total', 0)
                ->where('stats.totals.spent', '0.000000000000')
                ->where('stats.totals.success_rate', null)
                ->has('stats.daily', 14)
                ->has('stats.recent', 0));
    }

    public function test_stats_are_exact_and_scoped_to_the_user(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->count(3)->create();
        Generation::factory()->for($user)->failed()->create();
        Generation::factory()->for($user)->processing()->create();
        Generation::factory()->succeeded()->count(5)->create(); // someone else's

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.totals.total', 5)
                ->where('stats.totals.succeeded', 3)
                ->where('stats.totals.failed', 1)
                ->where('stats.totals.pending', 1)
                // 3 × 0.067575, failed one contributes 0
                ->where('stats.totals.spent', '0.202725000000')
                ->where('stats.totals.success_rate', 75)
                ->where('stats.daily.13.succeeded', 3)
                ->where('stats.daily.13.spent', '0.202725000000')
                ->has('stats.recent', 5));
    }
}
