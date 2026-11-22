<?php

namespace App\Services\Pixelforge;

use App\Services\Pixelforge\Data\Pricing;
use App\Services\Pixelforge\Data\ProviderGeneration;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use UnexpectedValueException;

/**
 * The only place PixelPilot talks to Pixelforge. Controllers and jobs use
 * these methods and never see HTTP details or credentials.
 *
 * Retries happen here only for failures the docs call retryable (429 with
 * Retry-After, 503 overloaded, network errors), with a small cap and short
 * waits so a web request never hangs. Creating a generation is retried only
 * with the same Idempotency-Key, so a retry can't create a second image.
 */
class PixelforgeClient
{
    public const RATE_LIMIT_CACHE_KEY = 'pixelforge:rate-limit-remaining';

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout,
        private readonly int $connectTimeout,
        private readonly int $maxAttempts,
        private readonly int $maxRetryWait,
    ) {}

    public function pricing(): Pricing
    {
        $response = $this->send('GET', '/pricing', authenticated: false);

        return $this->parse($response, fn (array $data) => Pricing::fromArray($data));
    }

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images?: list<string>, metadata?: array<string, string>}  $params
     * @return array{generation: ProviderGeneration, replayed: bool}
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        $response = $this->send('POST', '/generations', json: $params, headers: ['Idempotency-Key' => $idempotencyKey]);

        return [
            'generation' => $this->parse($response, fn (array $data) => ProviderGeneration::fromArray($data)),
            'replayed' => strtolower((string) $response->header('Idempotent-Replayed')) === 'true',
        ];
    }

    public function getGeneration(string $providerId): ProviderGeneration
    {
        $response = $this->send('GET', '/generations/'.rawurlencode($providerId));

        return $this->parse($response, fn (array $data) => ProviderGeneration::fromArray($data));
    }

    /**
     * @param  array{limit?: int, status?: string, cursor?: string}  $query
     * @return array{data: list<ProviderGeneration>, has_more: bool, next_cursor: ?string}
     */
    public function listGenerations(array $query = []): array
    {
        $response = $this->send('GET', '/generations', query: array_filter($query, fn ($v) => $v !== null));

        return $this->parse($response, fn (array $data) => [
            'data' => array_map(fn (array $g) => ProviderGeneration::fromArray($g), $data['data'] ?? []),
            'has_more' => (bool) ($data['has_more'] ?? false),
            'next_cursor' => $data['next_cursor'] ?? null,
        ]);
    }

    /**
     * Usage per UTC day for this API key. Dates are inclusive, ≤ 92 days.
     *
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        $response = $this->send('GET', '/usage', query: ['start_date' => $startDate, 'end_date' => $endDate]);

        return $this->parse($response, function (array $data) {
            if (! is_array($data['data'] ?? null)) {
                throw new UnexpectedValueException('Usage response has no data.');
            }

            return $data;
        });
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $path, ?array $json = null, array $query = [], array $headers = [], bool $authenticated = true): Response
    {
        if ($authenticated && blank($this->apiKey)) {
            throw new PixelforgeException('Pixelforge API key is not configured.', errorCode: 'invalid_api_key');
        }

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->request($headers, $authenticated)->send($method, $this->baseUrl.$path, array_filter([
                    'json' => $json,
                    'query' => $query ?: null,
                ]));
            } catch (ConnectionException $e) {
                $error = PixelforgeException::connection('Could not reach Pixelforge: '.$e->getMessage(), $e);
                $this->logFailure($method, $path, $error, $attempt);

                if ($attempt < $this->maxAttempts) {
                    Sleep::for($this->backoff($attempt, null))->seconds();

                    continue;
                }

                throw $error;
            }

            $this->rememberRateLimit($response);

            if ($response->successful()) {
                return $response;
            }

            $error = $this->toException($response);
            $this->logFailure($method, $path, $error, $attempt);

            // Concurrency limits clear when one of our generations finishes,
            // which takes seconds to minutes; that's the queue's job, not a
            // reason to block this process.
            $retryInline = $error->isRetryable()
                && $error->errorCode !== 'concurrency_limit_exceeded'
                && $attempt < $this->maxAttempts
                && ($error->retryAfter ?? 0) <= $this->maxRetryWait;

            if (! $retryInline) {
                throw $error;
            }

            Sleep::for($this->backoff($attempt, $error->retryAfter))->seconds();
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(array $headers, bool $authenticated): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withUserAgent('PixelPilot/1.0')
            ->withHeaders($headers);

        return $authenticated ? $request->withToken((string) $this->apiKey) : $request;
    }

    private function backoff(int $attempt, ?int $retryAfter): int
    {
        $wait = $retryAfter ?? (2 ** ($attempt - 1));

        return max(1, min($wait, $this->maxRetryWait));
    }

    private function toException(Response $response): PixelforgeException
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];
        $retryAfter = $response->header('Retry-After');

        return new PixelforgeException(
            message: (string) ($error['message'] ?? "Pixelforge responded with HTTP {$response->status()}."),
            status: $response->status(),
            errorCode: isset($error['code']) ? (string) $error['code'] : null,
            errorType: isset($error['type']) ? (string) $error['type'] : null,
            param: isset($error['param']) ? (string) $error['param'] : null,
            errors: is_array($error['errors'] ?? null) ? $error['errors'] : [],
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /**
     * @template T
     *
     * @param  Closure(array<string, mixed>): T  $map
     * @return T
     */
    private function parse(Response $response, Closure $map): mixed
    {
        $data = $response->json();

        try {
            if (! is_array($data)) {
                throw new UnexpectedValueException('Response body is not a JSON object.');
            }

            return $map($data);
        } catch (UnexpectedValueException $e) {
            Log::error('Pixelforge returned an unexpected response body.', ['status' => $response->status(), 'reason' => $e->getMessage()]);

            throw new PixelforgeException('Unexpected response from Pixelforge: '.$e->getMessage(), status: $response->status(), previous: $e);
        }
    }

    private function rememberRateLimit(Response $response): void
    {
        $remaining = $response->header('X-RateLimit-Remaining');

        if (is_numeric($remaining)) {
            Cache::put(self::RATE_LIMIT_CACHE_KEY, (int) $remaining, now()->addMinute());
        }
    }

    /**
     * Never logs the API key, request body or prompt; only what's needed to
     * diagnose the failure.
     */
    private function logFailure(string $method, string $path, PixelforgeException $error, int $attempt): void
    {
        $context = [
            'method' => $method,
            'path' => preg_replace('#/gen_[A-Za-z0-9]+#', '/gen_…', $path),
            'status' => $error->status,
            'code' => $error->errorCode,
            'retry_after' => $error->retryAfter,
            'attempt' => $attempt,
        ];

        if ($error->isRetryable()) {
            Log::warning('Pixelforge request failed (retryable).', $context);
        } else {
            Log::error('Pixelforge request failed.', $context);
        }
    }
}
