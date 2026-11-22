<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Generations\GenerationSubmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PixelforgeFixtures as F;
use Tests\TestCase;

class GenerationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->user = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'client_request_id' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    private function fakePixelforge(mixed $createResponse = null): void
    {
        Http::fake([
            F::BASE.'/pricing' => Http::response(F::pricing()),
            F::BASE.'/generations' => $createResponse ?? Http::response(F::generation('queued'), 202),
        ]);
    }

    public function test_generate_page_lists_supported_options_from_pricing(): void
    {
        $this->fakePixelforge();

        $this->actingAs($this->user)->get('/generate')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('generations/Create')
                ->where('options.sizes', ['1024x1024', '1536x1024', '1024x1536'])
                ->where('options.qualities', ['low', 'medium', 'high'])
                ->where('options.per_reference_image', 765)
                ->where('limits.prompt', 4000)
                ->where('limits.reference_images', 4)
                ->has('clientRequestId'));
    }

    public function test_generate_page_handles_pricing_outage(): void
    {
        Http::fake([F::BASE.'/pricing' => Http::response('', 503)]);

        $this->actingAs($this->user)->get('/generate')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('options', null));
    }

    public function test_estimate_endpoint_returns_the_documented_worst_case(): void
    {
        $this->fakePixelforge();

        // Docs' worked example: high, 1536x1024, 400 chars, 2 references → max cost 0.373875
        $this->actingAs($this->user)
            ->getJson('/generate/estimate?characters=400&size=1536x1024&quality=high&references=2')
            ->assertOk()
            ->assertJsonPath('cost_max', '0.373875000000')
            ->assertJsonPath('charge_max', '0.467343750000')
            ->assertJsonPath('tokens.input_text_tokens', ['min' => 85, 'max' => 115, 'estimated' => 100])
            ->assertJsonPath('tokens.input_image_tokens', ['min' => 1530, 'max' => 1530]);
    }

    public function test_submitting_creates_a_local_record_and_sends_it_with_an_idempotency_key(): void
    {
        $this->fakePixelforge();

        $response = $this->actingAs($this->user)->post('/generations', $this->input());

        $generation = Generation::sole();
        $response->assertRedirect(route('generations.show', $generation));

        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->provider_generation_id);
        $this->assertEquals(F::RATES, $generation->rates);
        $this->assertSame(1, $generation->submit_attempts);
        $this->assertNotNull($generation->next_poll_at);
        $this->assertNull($generation->actual_charge);
        // 45 chars → 12 estimated (10..14) text, 765 image, 6450 output, 1000..8000 reasoning tokens:
        // min (10×5 + 765×10 + 6450×40 + 1000×12.5) / 1e6 = 0.2782
        // max (14×5 + 765×10 + 6450×40 + 8000×12.5) / 1e6 = 0.36572
        $this->assertSame('0.278200000000', $generation->estimated_cost_min);
        $this->assertSame('0.365720000000', $generation->estimated_cost_max);
        $this->assertSame('0.347750000000', $generation->estimated_charge_min);
        $this->assertSame('0.457150000000', $generation->estimated_charge_max);

        Http::assertSent(function (Request $request) use ($generation) {
            return $request->url() === F::BASE.'/generations'
                && $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
                && $request['metadata'] === ['pixelpilot_generation' => $generation->uuid]
                && $request['prompt'] === 'A lighthouse on a cliff at dawn, oil painting'
                && $request['reference_images'] === ['https://example.com/style.png'];
        });
    }

    public function test_double_submit_with_the_same_form_token_creates_one_generation(): void
    {
        $this->fakePixelforge();
        $input = $this->input();

        $first = $this->actingAs($this->user)->post('/generations', $input);
        $second = $this->actingAs($this->user)->post('/generations', $input);

        $this->assertSame(1, Generation::count());
        $first->assertRedirect(route('generations.show', Generation::sole()));
        $second->assertRedirect(route('generations.show', Generation::sole()));
        Http::assertSentCount(2); // one pricing lookup + one create
    }

    public function test_content_policy_rejection_is_shown_on_the_prompt_and_costs_nothing(): void
    {
        $this->fakePixelforge(Http::response(F::error('content_policy_violation', 'invalid_request_error', 'The prompt was rejected.'), 400));

        $this->actingAs($this->user)
            ->from('/generate')
            ->post('/generations', $this->input(['prompt' => 'something [blocked]']))
            ->assertRedirect('/generate')
            ->assertSessionHasErrors('prompt')
            ->assertSessionHas('error');

        $generation = Generation::sole();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame('0.000000000000', $generation->actual_charge);
        $this->assertNull($generation->provider_generation_id);
    }

    public function test_overloaded_provider_is_retried_then_queued_for_later_with_the_same_key(): void
    {
        Queue::fake();
        $this->fakePixelforge(Http::response(F::error('overloaded', 'api_error', 'Overloaded'), 503, ['Retry-After' => '2']));

        $this->actingAs($this->user)->post('/generations', $this->input(['prompt' => 'x [overloaded]']))
            ->assertRedirect()
            ->assertSessionHas('success');

        $generation = Generation::sole();
        $this->assertSame(GenerationStatus::Submitting, $generation->status);
        Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $generation->id);

        $keys = Http::recorded(fn (Request $r) => $r->method() === 'POST')->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->unique();
        $this->assertCount(1, $keys);
        $this->assertSame($generation->idempotency_key, $keys->first());
    }

    public function test_concurrency_limit_queues_a_retry_after_the_given_delay(): void
    {
        Queue::fake();
        $this->fakePixelforge(Http::response(F::error('concurrency_limit_exceeded', 'rate_limit_error', 'Too many'), 429, ['Retry-After' => '5']));

        $this->actingAs($this->user)->post('/generations', $this->input());

        Queue::assertPushed(SubmitGeneration::class);
        $this->assertSame(GenerationStatus::Submitting, Generation::sole()->status);
    }

    public function test_retry_job_submits_with_the_original_key(): void
    {
        $this->fakePixelforge();
        $generation = Generation::factory()->for($this->user)->submitting()->create();

        (new SubmitGeneration($generation->id))->handle(app(GenerationSubmitter::class));

        $this->assertSame(GenerationStatus::Queued, $generation->fresh()->status);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', $generation->idempotency_key));
    }

    public function test_daily_limit_fails_the_generation_without_charge(): void
    {
        $this->fakePixelforge(Http::response(F::error('daily_limit_exceeded', 'rate_limit_error', 'Daily quota used'), 429));

        $this->actingAs($this->user)->from('/generate')->post('/generations', $this->input())->assertSessionHas('error');

        $generation = Generation::sole();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('0.000000000000', $generation->actual_charge);
    }

    public function test_input_is_validated_before_anything_is_sent(): void
    {
        $this->fakePixelforge();

        $cases = [
            'prompt' => ['prompt' => '   '],
            'size' => ['size' => '512x512'],
            'quality' => ['quality' => 'ultra'],
            'reference_images' => ['reference_images' => array_map(fn ($i) => "https://example.com/{$i}.png", range(1, 5))],
            'reference_images.0' => ['reference_images' => ['ftp://example.com/a.png']],
        ];

        foreach ($cases as $field => $override) {
            $this->actingAs($this->user)->post('/generations', $this->input($override))->assertSessionHasErrors($field);
        }

        $this->actingAs($this->user)->post('/generations', $this->input(['prompt' => str_repeat('é', 4001)]))->assertSessionHasErrors('prompt');
        $this->actingAs($this->user)->post('/generations', $this->input(['prompt' => str_repeat('é', 4000)]))->assertSessionHasNoErrors();

        Http::assertSentCount(2); // pricing + the one valid create
    }

    public function test_users_cannot_view_other_users_generations(): void
    {
        $other = Generation::factory()->create();

        $this->actingAs($this->user)->get(route('generations.show', $other))->assertForbidden();
        $this->actingAs($other->user)->get(route('generations.show', $other))->assertOk();
    }

    public function test_guests_cannot_generate(): void
    {
        $this->post('/generations', $this->input())->assertRedirect('/login');
        $this->get('/generate')->assertRedirect('/login');
        $this->assertSame(0, Generation::count());
    }
}
