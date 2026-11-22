<?php

namespace Tests\Feature\Generations;

use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\User;
use App\Services\Generations\GenerationImages;
use App\Services\Generations\GenerationSynchronizer;
use App\Services\Pixelforge\Data\ProviderGeneration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Support\PixelforgeFixtures as F;
use Tests\TestCase;

class GenerationHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_history_is_paginated_newest_first_and_scoped_to_the_user(): void
    {
        foreach (range(1, 13) as $i) {
            Generation::factory()->for($this->user)->create(['prompt' => "Prompt {$i}", 'created_at' => now()->subMinutes(100 - $i)]);
        }
        Generation::factory()->count(3)->create(); // someone else's

        $this->actingAs($this->user)->get('/generations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('generations/Index')
                ->where('generations.total', 13)
                ->has('generations.data', 12)
                ->where('generations.data.0.prompt', 'Prompt 13')
                ->where('hasAny', true));

        $this->actingAs($this->user)->get('/generations?page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('generations.data', 1)
                ->where('generations.data.0.prompt', 'Prompt 1'));
    }

    public function test_sorting_oldest_first(): void
    {
        Generation::factory()->for($this->user)->create(['prompt' => 'older', 'created_at' => now()->subDay()]);
        Generation::factory()->for($this->user)->create(['prompt' => 'newer']);

        $this->actingAs($this->user)->get('/generations?sort=oldest')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('generations.data.0.prompt', 'older'));
    }

    public function test_search_is_case_insensitive_and_treats_wildcards_literally(): void
    {
        Generation::factory()->for($this->user)->create(['prompt' => 'A red Lighthouse']);
        Generation::factory()->for($this->user)->create(['prompt' => 'A blue whale']);
        Generation::factory()->for($this->user)->create(['prompt' => 'Sale: 50% off poster']);

        $this->actingAs($this->user)->get('/generations?search=lighthouse')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('generations.data', 1)
                ->where('generations.data.0.prompt', 'A red Lighthouse'));

        $this->actingAs($this->user)->get('/generations?search='.urlencode('50%'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('generations.data', 1));

        $this->actingAs($this->user)->get('/generations?search=%25')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('generations.data', 1));
    }

    public function test_status_filter_maps_to_user_facing_statuses(): void
    {
        Generation::factory()->for($this->user)->submitting()->create();
        Generation::factory()->for($this->user)->create(); // queued
        Generation::factory()->for($this->user)->processing()->create();
        Generation::factory()->for($this->user)->succeeded()->count(2)->create();
        Generation::factory()->for($this->user)->failed()->create();

        $expect = ['queued' => 2, 'processing' => 1, 'succeeded' => 2, 'failed' => 1];

        foreach ($expect as $status => $count) {
            $this->actingAs($this->user)->get("/generations?status={$status}")
                ->assertInertia(fn (AssertableInertia $page) => $page->where('generations.total', $count));
        }

        $this->actingAs($this->user)->get('/generations?status=bogus')->assertSessionHasErrors('status');
    }

    public function test_cards_show_estimate_actual_charge_and_zero_for_failures(): void
    {
        Generation::factory()->for($this->user)->failed()->create(['created_at' => now()->subMinute()]);
        Generation::factory()->for($this->user)->succeeded()->create();

        $this->actingAs($this->user)->get('/generations')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generations.data.0.status', 'succeeded')
                ->where('generations.data.0.actual_charge', '0.067575000000')
                ->where('generations.data.0.estimated_charge_min', '0.058175000000')
                ->where('generations.data.0.image_url', 'https://pixelforge.promptedit.dev/images/example.png')
                ->where('generations.data.1.status', 'failed')
                ->where('generations.data.1.actual_charge', '0.000000000000'));
    }

    public function test_expired_image_urls_are_not_sent_to_the_browser(): void
    {
        $generation = Generation::factory()->for($this->user)->succeeded()->create(['image_url_expires_at' => now()->subMinute()]);

        $this->actingAs($this->user)->get(route('generations.show', $generation))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generation.image_url', null)
                ->where('generation.image_expired', true));
    }

    public function test_empty_history(): void
    {
        $this->actingAs($this->user)->get('/generations')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('hasAny', false)->has('generations.data', 0));
    }

    public function test_success_queues_an_image_copy_when_storage_is_enabled(): void
    {
        config(['pixelpilot.images.store' => true]);
        Queue::fake();
        $generation = Generation::factory()->for($this->user)->create();

        app(GenerationSynchronizer::class)->apply($generation, ProviderGeneration::fromArray(F::generation('succeeded', ['id' => $generation->provider_generation_id])), 'test');

        Queue::assertPushed(StoreGenerationImage::class, fn ($job) => $job->generationId === $generation->id);
    }

    public function test_stored_image_is_served_to_its_owner_only(): void
    {
        config(['pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1', 'pixelpilot.images.disk' => 'local']);
        Storage::fake('local');
        Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);
        $generation = Generation::factory()->for($this->user)->succeeded()->create();

        (new StoreGenerationImage($generation->id))->handle(app(GenerationImages::class));

        $generation->refresh();
        $this->assertSame("generations/{$generation->uuid}.png", $generation->image_path);
        Storage::disk('local')->assertExists($generation->image_path);

        // Once stored, the app serves its own copy, even after Pixelforge's link expires.
        $generation->forceFill(['image_url_expires_at' => now()->subDay()])->save();
        $this->actingAs($this->user)->get(route('generations.show', $generation))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('generation.image_url', route('generations.image', $generation)));

        $this->actingAs($this->user)->get(route('generations.image', $generation))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('generations.image', $generation))->assertForbidden();
    }

    public function test_image_download_refuses_non_pixelforge_hosts(): void
    {
        config(['pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1']);
        Http::fake();
        $generation = Generation::factory()->for($this->user)->succeeded()->create(['image_url' => 'https://169.254.169.254/latest/meta-data']);

        $this->expectException(RuntimeException::class);

        try {
            app(GenerationImages::class)->store($generation);
        } finally {
            Http::assertNothingSent();
        }
    }
}
