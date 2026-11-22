<?php

namespace Tests\Feature\Scenarios;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\GenerationCharge;
use App\Models\WebhookEvent;
use App\Services\Generations\GenerationPoller;
use App\Services\Generations\GenerationSynchronizer;
use App\Services\Pixelforge\Data\ProviderGeneration;
use App\Services\Pixelforge\Webhooks\WebhookProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\PixelforgeFixtures as F;
use Tests\TestCase;

/**
 * Scenario 17: simultaneous processing attempts. These tests use a second,
 * real database connection to hold row locks while the app tries to work,
 * so they commit data (and truncate afterwards) instead of running inside
 * a wrapping transaction.
 */
class ConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const OTHER = 'pgsql_other';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.'.self::OTHER => config('database.connections.pgsql')]);
    }

    protected function tearDown(): void
    {
        DB::connection(self::OTHER)->rollBack();
        DB::purge(self::OTHER);

        // These tests commit; leave nothing behind for the transactional tests that follow.
        // (TRUNCATE bypasses the row-level immutability trigger on generation_charges.)
        DB::statement('TRUNCATE generation_charges, webhook_events, generations, users RESTART IDENTITY CASCADE');

        parent::tearDown();
    }

    private function holdLock(string $sql, array $bindings): void
    {
        $other = DB::connection(self::OTHER);
        $other->beginTransaction();
        $other->select($sql, $bindings);
    }

    public function test_a_second_worker_waits_for_the_generation_lock_and_then_charges_nothing_extra(): void
    {
        $generation = Generation::factory()->create(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
        $snapshot = ProviderGeneration::fromArray(F::generation('succeeded'));

        // Another worker is mid-way through processing this generation.
        $this->holdLock('select id from generations where id = ? for update', [$generation->id]);

        DB::statement("set lock_timeout = '300ms'");
        try {
            app(GenerationSynchronizer::class)->apply($generation, $snapshot, 'worker-2');
            $this->fail('Expected to wait on the row lock.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock', strtolower($e->getMessage()));
        }

        $this->assertSame(GenerationStatus::Queued, $generation->fresh()->status);
        $this->assertSame(0, GenerationCharge::count());

        // The other worker finishes (here: without changes) and releases the lock.
        DB::connection(self::OTHER)->rollBack();

        app(GenerationSynchronizer::class)->apply($generation, $snapshot, 'worker-2');
        app(GenerationSynchronizer::class)->apply($generation, $snapshot, 'worker-3');

        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()->status);
        $this->assertSame(1, GenerationCharge::count());
        DB::statement('set lock_timeout = 0');
    }

    public function test_the_same_event_cannot_be_processed_by_two_workers_at_once(): void
    {
        $generation = Generation::factory()->create(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
        $payload = json_encode(F::event('generation.succeeded', F::generation('succeeded'), 'evt_race'));
        $event = WebhookEvent::create([
            'event_id' => 'evt_race',
            'type' => 'generation.succeeded',
            'provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'payload' => $payload,
            'received_at' => now(),
            'last_received_at' => now(),
        ]);

        $this->holdLock('select id from webhook_events where id = ? for update', [$event->id]);

        DB::statement("set lock_timeout = '300ms'");
        try {
            app(WebhookProcessor::class)->process($event->id);
            $this->fail('Expected to wait on the event lock.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        DB::connection(self::OTHER)->rollBack();
        DB::statement('set lock_timeout = 0');

        $this->assertSame(WebhookEvent::PROCESSED, app(WebhookProcessor::class)->process($event->id));
        $this->assertSame(WebhookEvent::PROCESSED, app(WebhookProcessor::class)->process($event->id));
        $this->assertSame(1, GenerationCharge::count());
        $this->assertSame(1, $event->fresh()->processing_attempts);
        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()->status);
    }

    public function test_two_schedulers_claim_disjoint_sets_of_generations(): void
    {
        foreach (range(1, 6) as $i) {
            Generation::factory()->processing()->create(['provider_generation_id' => "gen_{$i}", 'next_poll_at' => now()->subMinute()]);
        }

        // Scheduler A claims 3 rows inside an open transaction (still holding their locks).
        $other = DB::connection(self::OTHER);
        $other->beginTransaction();
        $claimedByA = collect($other->select(
            "select id from generations where status = 'processing' and next_poll_at <= now() order by id limit 3 for update skip locked"
        ))->pluck('id')->all();

        // Scheduler B runs at the same moment and must skip A's rows.
        $claimedByB = app(GenerationPoller::class)->claimDue(10)->all();

        $this->assertCount(3, $claimedByA);
        $this->assertCount(3, $claimedByB);
        $this->assertSame([], array_intersect($claimedByA, $claimedByB));
    }
}
