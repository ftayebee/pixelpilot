<?php

namespace Tests\Feature\Admin;

use App\Models\Generation;
use App\Models\User;
use App\Services\Admin\ProviderUsageSync;
use App\Services\Generations\GenerationSynchronizer;
use App\Services\Pixelforge\Data\ProviderGeneration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PixelforgeFixtures as F;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    /**
     * A real billed generation (charge row via the synchronizer), completed on the given UTC day.
     */
    private function billed(User $user, string $completedAt, array $usage = []): Generation
    {
        static $n = 0;
        $n++;
        $generation = Generation::factory()->for($user)->create(['provider_generation_id' => "gen_A{$n}"]);
        app(GenerationSynchronizer::class)->apply($generation, ProviderGeneration::fromArray(F::generation('succeeded', [
            'id' => "gen_A{$n}",
            'completed_at' => $completedAt,
            'usage' => $usage,
        ])), 'test');

        return $generation;
    }

    private function url(array $query = []): string
    {
        return route('admin.analytics', ['start' => '2026-11-01', 'end' => '2026-11-05', ...$query]);
    }

    public function test_only_admins_can_see_the_report(): void
    {
        $this->get('/admin/analytics')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/analytics')->assertForbidden();
        $this->actingAs(User::factory()->create())->post('/admin/analytics/sync')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/analytics')->assertOk();
    }

    public function test_daily_and_per_user_money_is_exact(): void
    {
        $ada = User::factory()->create(['name' => 'Ada']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $this->billed($ada, '2026-11-02T10:00:00Z');                    // 0.30551 / 0.3818875
        $this->billed($ada, '2026-11-02T23:59:59Z');                    // same UTC day
        $this->billed($bob, '2026-11-03T00:00:00Z', ['reasoning_tokens' => 1000, 'total_tokens' => 1]); // 12×5+765×10+6450×40+1000×12.5 = 278,210
        Generation::factory()->for($bob)->failed()->create(['completed_at' => '2026-11-03T05:00:00Z']);

        $this->actingAs($this->admin)->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/Analytics')
                ->where('report.summary.charges', '1.111537500000')
                ->where('report.summary.costs', '0.889230000000')
                ->where('report.summary.profit', '0.222307500000')
                ->where('report.summary.succeeded', 3)
                ->where('report.summary.failed', 1)
                ->where('report.daily.1.date', '2026-11-02')
                ->where('report.daily.1.charges', '0.763775000000')
                ->where('report.daily.1.costs', '0.611020000000')
                ->where('report.daily.1.profit', '0.152755000000')
                ->where('report.daily.2.date', '2026-11-03')
                ->where('report.daily.2.costs', '0.278210000000')
                ->where('report.daily.2.failed', 1)
                ->where('report.users.0.name', 'Ada')
                ->where('report.users.0.profit', '0.152755000000')
                ->where('report.users.1.name', 'Bob')
                ->where('report.users.1.failed', 1));
    }

    public function test_user_filter_scopes_everything_and_hides_reconciliation(): void
    {
        $ada = User::factory()->create();
        $bob = User::factory()->create();
        $this->billed($ada, '2026-11-02T10:00:00Z');
        $this->billed($bob, '2026-11-02T10:00:00Z');

        $this->actingAs($this->admin)->get($this->url(['user' => $ada->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.summary.charges', '0.381887500000')
                ->has('report.users', 1)
                ->where('report.reconciliation', null));
    }

    public function test_reconciliation_matches_pixelforge_usage_exactly(): void
    {
        $this->billed(User::factory()->create(), '2026-11-02T10:00:00Z');
        Http::fake([F::BASE.'/usage*' => Http::response($this->usage([
            '2026-11-01' => [0, '0.000000000'],
            '2026-11-02' => [1, '0.305510000'],
            '2026-11-03' => [0, '0.000000000'],
            '2026-11-04' => [0, '0.000000000'],
            '2026-11-05' => [0, '0.000000000'],
        ]))]);

        $this->actingAs($this->admin)->post(route('admin.analytics.sync', ['start' => '2026-11-01', 'end' => '2026-11-05']))
            ->assertSessionHas('success');

        $this->actingAs($this->admin)->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.reconciliation.status', 'match')
                ->where('report.reconciliation.mismatches', 0)
                ->where('report.reconciliation.pixelforge_total', '0.305510000000'));
    }

    public function test_mismatches_are_shown_not_hidden(): void
    {
        $this->billed(User::factory()->create(), '2026-11-02T10:00:00Z');
        Http::fake([F::BASE.'/usage*' => Http::response($this->usage([
            '2026-11-02' => [2, '0.405510000'],
        ]))]);

        $this->actingAs($this->admin)->post(route('admin.analytics.sync', ['start' => '2026-11-02', 'end' => '2026-11-02']));

        $this->actingAs($this->admin)->get(route('admin.analytics', ['start' => '2026-11-02', 'end' => '2026-11-02']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.reconciliation.status', 'mismatch')
                ->where('report.reconciliation.days.0.difference', '-0.100000000000')
                ->where('report.reconciliation.days.0.our_generations', 1)
                ->where('report.reconciliation.days.0.pixelforge_generations', 2));
    }

    public function test_sync_splits_long_ranges_into_92_day_requests(): void
    {
        Http::fake([F::BASE.'/usage*' => Http::response($this->usage([]))]);

        app(ProviderUsageSync::class)->sync(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-07-01'),
        );

        $ranges = Http::recorded()->map(fn ($pair) => [$pair[0]['start_date'], $pair[0]['end_date']])->all();
        $this->assertSame([
            ['2026-01-01', '2026-04-02'],
            ['2026-04-03', '2026-07-01'],
        ], $ranges);
    }

    public function test_resync_records_corrections_and_keys_never_mix(): void
    {
        Http::fake([F::BASE.'/usage*' => Http::sequence()
            ->push($this->usage(['2026-11-02' => [1, '0.305510000']]))
            ->push($this->usage(['2026-11-02' => [1, '0.305520000']]))]);
        $sync = app(ProviderUsageSync::class);

        $sync->sync(CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-02'));
        $sync->sync(CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-02'));

        $row = DB::table('provider_usage_days')->sole();
        $this->assertSame('0.305520000000', $row->cost_usd);
        $this->assertSame('0.305510000000', $row->previous_cost_usd);

        // A different API key (environment) gets its own rows.
        config(['pixelforge.api_key' => 'another_environment_key']);
        $this->assertNotSame($row->key_fingerprint, ProviderUsageSync::keyFingerprint());
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer test_key'));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs($this->admin)->get(route('admin.analytics', ['start' => '2026-11-05', 'end' => '2026-11-01']))->assertSessionHasErrors('end');
        $this->actingAs($this->admin)->get(route('admin.analytics', ['user' => 999999]))->assertSessionHasErrors('user');
    }

    /**
     * @param  array<string, array{int, string}>  $days
     */
    private function usage(array $days): array
    {
        return [
            'object' => 'usage',
            'data' => collect($days)->map(fn ($d, $date) => [
                'date' => $date,
                'generations' => $d[0],
                'input_text_tokens' => 0,
                'input_image_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'cost_usd' => $d[1],
            ])->values()->all(),
            'total' => [],
        ];
    }
}
