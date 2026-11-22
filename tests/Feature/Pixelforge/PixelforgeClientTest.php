<?php

namespace Tests\Feature\Pixelforge;

use App\Enums\GenerationStatus;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Support\PixelforgeFixtures as F;
use Tests\TestCase;

class PixelforgeClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function client(): PixelforgeClient
    {
        return app(PixelforgeClient::class);
    }

    public function test_pricing_is_parsed_without_floats_and_without_authentication(): void
    {
        Http::fake([F::BASE.'/pricing' => Http::response(F::pricing())]);

        $pricing = $this->client()->pricing();

        $this->assertSame('12.50', $pricing->rates['reasoning_tokens']);
        $this->assertSame('0.15', $pricing->textTokenVariance);
        $this->assertSame(765, $pricing->tokensPerReferenceImage);
        $this->assertSame(6450, $pricing->outputTokens['high']['1536x1024']);
        $this->assertSame(['min' => 1000, 'max' => 8000], $pricing->reasoningTokens['high']);
        $this->assertSame(['1024x1024', '1536x1024', '1024x1536'], $pricing->sizes());
        $this->assertSame(['low', 'medium', 'high'], $pricing->qualities());

        Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization'));
    }

    public function test_create_sends_bearer_token_idempotency_key_and_exact_params(): void
    {
        Http::fake([F::BASE.'/generations' => Http::response(F::generation('queued'), 202)]);

        $result = $this->client()->createGeneration([
            'prompt' => 'A lighthouse',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => ['generation_id' => 'abc'],
        ], 'key-123');

        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $result['generation']->id);
        $this->assertSame(GenerationStatus::Queued, $result['generation']->status);
        $this->assertFalse($result['replayed']);

        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && $r->hasHeader('Authorization', 'Bearer test_key')
                && $r->hasHeader('Idempotency-Key', 'key-123')
                && $r->data() === [
                    'prompt' => 'A lighthouse',
                    'size' => '1536x1024',
                    'quality' => 'high',
                    'reference_images' => ['https://example.com/style.png'],
                    'metadata' => ['generation_id' => 'abc'],
                ];
        });
    }

    public function test_idempotent_replay_is_detected(): void
    {
        Http::fake([F::BASE.'/generations' => Http::response(F::generation('processing'), 202, ['Idempotent-Replayed' => 'true'])]);

        $result = $this->client()->createGeneration(['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'], 'key');

        $this->assertTrue($result['replayed']);
        $this->assertSame(GenerationStatus::Processing, $result['generation']->status);
    }

    public function test_overloaded_create_is_retried_with_the_same_idempotency_key(): void
    {
        Http::fake([
            F::BASE.'/generations' => Http::sequence()
                ->push(F::error('overloaded', 'api_error', 'Pixelforge is temporarily overloaded.'), 503, ['Retry-After' => '2'])
                ->push(F::generation('queued'), 202),
        ]);

        $result = $this->client()->createGeneration(['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'], 'same-key');

        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $result['generation']->id);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', 'same-key'));
        Http::assertNotSent(fn (Request $r) => ! $r->hasHeader('Idempotency-Key', 'same-key'));
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 2, 1);
    }

    public function test_network_timeout_on_create_is_retried_with_the_same_key(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            // The first request actually reached Pixelforge; the replay returns it.
            return Http::response(F::generation('queued'), 202, ['Idempotent-Replayed' => 'true']);
        });

        $result = $this->client()->createGeneration(['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'], 'k');

        $this->assertTrue($result['replayed']);
        $this->assertSame(2, $calls);
    }

    public function test_retries_are_capped(): void
    {
        Http::fake([F::BASE.'/generations' => Http::response(F::error('overloaded', 'api_error', 'Overloaded'), 503, ['Retry-After' => '2'])]);

        try {
            $this->client()->createGeneration(['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'], 'k');
            $this->fail('Expected an exception.');
        } catch (PixelforgeException $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame('overloaded', $e->errorCode);
            $this->assertTrue($e->isRetryable());
        }

        Http::assertSentCount(3);
    }

    public function test_concurrency_limit_is_not_retried_inline(): void
    {
        Http::fake([F::BASE.'/generations' => Http::response(F::error('concurrency_limit_exceeded', 'rate_limit_error', 'Too many'), 429, ['Retry-After' => '5'])]);

        try {
            $this->client()->createGeneration(['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'], 'k');
            $this->fail('Expected an exception.');
        } catch (PixelforgeException $e) {
            $this->assertSame('concurrency_limit_exceeded', $e->errorCode);
            $this->assertSame(5, $e->retryAfter);
            $this->assertTrue($e->isRetryable());
        }

        Http::assertSentCount(1);
    }

    public function test_rate_limit_waits_for_retry_after_then_succeeds(): void
    {
        Http::fake([
            F::BASE.'/generations/*' => Http::sequence()
                ->push(F::error('rate_limit_exceeded', 'rate_limit_error', 'Too many requests'), 429, ['Retry-After' => '3'])
                ->push(F::generation('processing'), 200),
        ]);

        $generation = $this->client()->getGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

        $this->assertSame(GenerationStatus::Processing, $generation->status);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 3, 1);
    }

    public function test_non_retryable_errors_are_mapped_and_not_retried(): void
    {
        $cases = [
            [400, F::error('content_policy_violation', 'invalid_request_error', 'The prompt was rejected.'), 'content_policy_violation'],
            [401, F::error('invalid_api_key', 'authentication_error', 'Invalid key'), 'invalid_api_key'],
            [404, F::error('not_found', 'invalid_request_error', 'No such generation'), 'not_found'],
            [409, F::error('idempotency_key_reused', 'idempotency_error', 'Key reused'), 'idempotency_key_reused'],
            [422, F::error('validation_failed', 'invalid_request_error', 'The selected size is invalid.', 'size'), 'validation_failed'],
            [429, F::error('daily_limit_exceeded', 'rate_limit_error', 'Daily quota used'), 'daily_limit_exceeded'],
        ];

        $current = null;
        $calls = 0;
        Http::fake(function () use (&$current, &$calls) {
            $calls++;

            return Http::response($current[1], $current[0]);
        });

        foreach ($cases as $case) {
            [$status, , $code] = $current = $case;
            $calls = 0;

            try {
                $this->client()->createGeneration(['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'], 'k');
                $this->fail("Expected exception for {$code}.");
            } catch (PixelforgeException $e) {
                $this->assertSame($status, $e->status, $code);
                $this->assertSame($code, $e->errorCode);
                $this->assertFalse($e->isRetryable(), $code);
            }

            $this->assertSame(1, $calls, $code);
        }
    }

    public function test_validation_error_exposes_param(): void
    {
        Http::fake([F::BASE.'/*' => Http::response(F::error('validation_failed', 'invalid_request_error', 'The selected size is invalid.', 'size'), 422)]);

        try {
            $this->client()->createGeneration(['prompt' => 'x', 'size' => '9x9', 'quality' => 'low'], 'k');
        } catch (PixelforgeException $e) {
            $this->assertTrue($e->isValidationError());
            $this->assertSame('size', $e->param);
        }
    }

    public function test_generic_500_is_retried_and_treated_as_ambiguous(): void
    {
        Http::fake([F::BASE.'/*' => Http::response('oops', 500)]);

        try {
            $this->client()->getGeneration('gen_x');
        } catch (PixelforgeException $e) {
            $this->assertSame(500, $e->status);
            $this->assertTrue($e->isRetryable());
            $this->assertTrue($e->isAmbiguous());
        }

        Http::assertSentCount(3);
    }

    public function test_succeeded_generation_parses_usage_and_rates(): void
    {
        Http::fake([F::BASE.'/generations/*' => Http::response(F::generation('succeeded'))]);

        $g = $this->client()->getGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

        $this->assertSame(GenerationStatus::Succeeded, $g->status);
        $this->assertSame(3184, $g->usage['reasoning_tokens']);
        $this->assertSame('12.50', $g->rates['reasoning_tokens']);
        $this->assertSame('2026-11-05T14:03:29+00:00', $g->imageUrlExpiresAt?->toIso8601String());
    }

    public function test_malformed_response_becomes_a_pixelforge_exception(): void
    {
        Http::fake([F::BASE.'/generations/*' => Http::response(['status' => 'succeeded'])]);

        $this->expectException(PixelforgeException::class);

        $this->client()->getGeneration('gen_x');
    }

    public function test_list_and_usage_endpoints(): void
    {
        Http::fake([
            F::BASE.'/generations?*' => Http::response(['object' => 'list', 'data' => [F::generation('queued')], 'has_more' => true, 'next_cursor' => 'abc']),
            F::BASE.'/usage?*' => Http::response(['object' => 'usage', 'data' => [], 'total' => []]),
        ]);

        $list = $this->client()->listGenerations(['limit' => 1, 'status' => 'queued']);
        $this->assertCount(1, $list['data']);
        $this->assertTrue($list['has_more']);
        $this->assertSame('abc', $list['next_cursor']);

        $usage = $this->client()->usage('2026-11-01', '2026-11-02');
        $this->assertSame('usage', $usage['object']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'start_date=2026-11-01') && str_contains($r->url(), 'end_date=2026-11-02'));
    }

    public function test_api_key_never_appears_in_logs(): void
    {
        Log::spy();
        Http::fake([F::BASE.'/*' => Http::response(F::error('invalid_api_key', 'authentication_error', 'Bad key'), 401)]);

        try {
            $this->client()->getGeneration('gen_x');
        } catch (PixelforgeException) {
        }

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) {
            return ! str_contains(json_encode($context), 'test_key');
        });
    }
}
