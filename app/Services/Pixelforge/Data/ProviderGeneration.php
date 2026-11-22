<?php

namespace App\Services\Pixelforge\Data;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use UnexpectedValueException;

/**
 * Pixelforge's generation object, as returned by the API and embedded in
 * webhook events (data.object).
 */
final readonly class ProviderGeneration
{
    /**
     * @param  array<string, string>  $rates
     * @param  array<string, string>  $metadata
     * @param  list<string>  $referenceImages
     * @param  array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int}|null  $usage
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public string $prompt,
        public string $size,
        public string $quality,
        public array $referenceImages,
        public array $metadata,
        public array $rates,
        public ?array $usage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $status = GenerationStatus::tryFrom((string) ($data['status'] ?? ''));

        if (! is_string($id) || ! str_starts_with($id, 'gen_') || $status === null || $status === GenerationStatus::Submitting) {
            throw new UnexpectedValueException('Malformed Pixelforge generation object.');
        }

        $usage = null;
        if (is_array($data['usage'] ?? null)) {
            $usage = [];
            foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens', 'total_tokens'] as $key) {
                $value = $data['usage'][$key] ?? null;
                if (! is_int($value) || $value < 0) {
                    throw new UnexpectedValueException("Malformed usage.{$key} on {$id}.");
                }
                $usage[$key] = $value;
            }
        }

        if ($status === GenerationStatus::Succeeded && $usage === null) {
            throw new UnexpectedValueException("Succeeded generation {$id} has no usage.");
        }

        $rates = [];
        foreach ((array) ($data['rates'] ?? []) as $key => $value) {
            $rates[(string) $key] = (string) $value;
        }

        return new self(
            id: $id,
            status: $status,
            prompt: (string) ($data['prompt'] ?? ''),
            size: (string) ($data['size'] ?? ''),
            quality: (string) ($data['quality'] ?? ''),
            referenceImages: array_values(array_map('strval', (array) ($data['reference_images'] ?? []))),
            metadata: array_map('strval', (array) ($data['metadata'] ?? [])),
            rates: $rates,
            usage: $usage,
            imageUrl: isset($data['image_url']) ? (string) $data['image_url'] : null,
            imageUrlExpiresAt: self::time($data['image_url_expires_at'] ?? null),
            errorCode: isset($data['error']['code']) ? (string) $data['error']['code'] : null,
            errorMessage: isset($data['error']['message']) ? (string) $data['error']['message'] : null,
            createdAt: self::time($data['created_at'] ?? null),
            startedAt: self::time($data['started_at'] ?? null),
            completedAt: self::time($data['completed_at'] ?? null),
            raw: $data,
        );
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
