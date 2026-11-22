<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\GenerationCharge;
use App\Services\Generations\GenerationSynchronizer;
use App\Services\Pixelforge\Data\ProviderGeneration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PixelforgeFixtures as F;
use Tests\TestCase;

class ChargingTest extends TestCase
{
    use RefreshDatabase;

    private function sync(Generation $generation, string $status, array $overrides = []): bool
    {
        $snapshot = ProviderGeneration::fromArray(F::generation($status, [
            'id' => $generation->provider_generation_id ?? 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            ...$overrides,
        ]));

        return app(GenerationSynchronizer::class)->apply($generation, $snapshot, 'test');
    }

    public function test_success_records_one_charge_from_actual_usage(): void
    {
        $generation = Generation::factory()->create();

        $this->assertTrue($this->sync($generation, 'succeeded'));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame('0.305510000000', $generation->actual_cost);
        $this->assertSame('0.381887500000', $generation->actual_charge);
        $this->assertSame(3184, $generation->reasoning_tokens);
        $this->assertNotNull($generation->charged_at);

        $charge = GenerationCharge::sole();
        $this->assertSame($generation->id, $charge->generation_id);
        $this->assertSame('0.305510000000', $charge->provider_cost);
        $this->assertSame('0.381887500000', $charge->customer_charge);
        $this->assertSame('1.2500', $charge->markup);
        $this->assertSame('2026-11-04', $charge->billed_on->toDateString());
    }

    public function test_charge_ignores_the_estimate(): void
    {
        $generation = Generation::factory()->create([
            'estimated_charge_min' => '9.000000000000',
            'estimated_charge_max' => '9.000000000000',
        ]);

        $this->sync($generation, 'succeeded');

        $this->assertSame('0.381887500000', $generation->fresh()->actual_charge);
    }

    public function test_duplicate_success_does_not_charge_twice(): void
    {
        $generation = Generation::factory()->create();

        $this->sync($generation, 'succeeded');
        $this->sync($generation, 'succeeded');
        $this->sync($generation, 'succeeded', ['usage' => ['reasoning_tokens' => 1, 'total_tokens' => 1]]);

        $this->assertSame(1, GenerationCharge::count());
        $this->assertSame('0.381887500000', $generation->fresh()->actual_charge);
    }

    public function test_success_never_reverts(): void
    {
        $generation = Generation::factory()->create();
        $this->sync($generation, 'succeeded');

        $this->assertFalse($this->sync($generation, 'processing'));
        $this->assertFalse($this->sync($generation, 'queued'));
        $this->assertFalse($this->sync($generation, 'failed'));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame('0.381887500000', $generation->actual_charge);
        $this->assertNull($generation->error_code);
    }

    public function test_failed_generation_is_free_and_final(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->sync($generation, 'failed');
        $this->sync($generation, 'succeeded');

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('0.000000000000', $generation->actual_charge);
        $this->assertSame('0.000000000000', $generation->actual_cost);
        $this->assertSame('internal_error', $generation->error_code);
        $this->assertSame(0, GenerationCharge::count());
    }

    public function test_charge_uses_the_rates_fixed_on_the_generation(): void
    {
        $generation = Generation::factory()->create();
        $rates = ['input_text_tokens' => '6.00', 'input_image_tokens' => '10.00', 'output_tokens' => '42.50', 'reasoning_tokens' => '12.75'];

        $this->sync($generation, 'succeeded', ['rates' => $rates]);

        // 12×6 + 765×10 + 6450×42.5 + 3184×12.75 = 72 + 7,650 + 274,125 + 40,596 = 322,443
        $this->assertSame('0.322443000000', $generation->fresh()->actual_cost);
        $this->assertSame('0.403053750000', $generation->fresh()->actual_charge);
    }

    public function test_database_refuses_to_move_a_final_generation(): void
    {
        $generation = Generation::factory()->create();
        $this->sync($generation, 'succeeded');

        $this->expectException(QueryException::class);
        DB::table('generations')->where('id', $generation->id)->update(['status' => 'processing']);
    }

    public function test_database_refuses_to_change_a_recorded_charge(): void
    {
        $generation = Generation::factory()->create();
        $this->sync($generation, 'succeeded');

        $this->expectException(QueryException::class);
        DB::table('generations')->where('id', $generation->id)->update(['actual_charge' => '0.01']);
    }

    public function test_database_refuses_a_second_charge_for_the_same_generation(): void
    {
        $generation = Generation::factory()->create();
        $this->sync($generation, 'succeeded');
        $row = GenerationCharge::sole()->getAttributes();
        unset($row['id']);
        $row['provider_generation_id'] = 'gen_other';

        $this->expectException(QueryException::class);
        DB::table('generation_charges')->insert($row);
    }

    public function test_database_refuses_a_charge_that_does_not_match_its_usage(): void
    {
        $generation = Generation::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('generation_charges')->insert([
            'generation_id' => $generation->id,
            'user_id' => $generation->user_id,
            'provider_generation_id' => 'gen_x',
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'rate_input_text' => '5.00',
            'rate_input_image' => '10.00',
            'rate_output' => '40.00',
            'rate_reasoning' => '12.50',
            'markup' => '1.25',
            'provider_cost' => '0.000049', // should be 0.00005
            'customer_charge' => '0.00006125',
            'billed_on' => '2026-11-04',
        ]);
    }

    public function test_database_refuses_a_charge_without_the_markup(): void
    {
        $generation = Generation::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('generation_charges')->insert([
            'generation_id' => $generation->id,
            'user_id' => $generation->user_id,
            'provider_generation_id' => 'gen_x',
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'rate_input_text' => '5.00',
            'rate_input_image' => '10.00',
            'rate_output' => '40.00',
            'rate_reasoning' => '12.50',
            'markup' => '1.25',
            'provider_cost' => '0.00005',
            'customer_charge' => '0.00005',
            'billed_on' => '2026-11-04',
        ]);
    }

    public function test_charges_are_immutable(): void
    {
        $generation = Generation::factory()->create();
        $this->sync($generation, 'succeeded');

        $this->expectException(QueryException::class);
        GenerationCharge::query()->delete();
    }
}
