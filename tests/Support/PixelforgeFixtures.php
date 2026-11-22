<?php

namespace Tests\Support;

/**
 * Deterministic Pixelforge payloads, shaped exactly like the documented
 * responses, for use with Http::fake().
 */
final class PixelforgeFixtures
{
    public const BASE = 'https://pixelforge.test/v1';

    public const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function pricing(array $rates = self::RATES): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => $rates,
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function generation(string $status = 'queued', array $overrides = []): array
    {
        $base = [
            'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'object' => 'generation',
            'status' => $status,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => [],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => self::RATES,
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => null,
            'completed_at' => null,
        ];

        if ($status === 'processing' || $status === 'succeeded' || $status === 'failed') {
            $base['started_at'] = '2026-11-04T14:03:13Z';
        }

        if ($status === 'succeeded') {
            $base['image_url'] = 'https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?expires=1762351409&signature=abc';
            $base['image_url_expires_at'] = '2026-11-05T14:03:29Z';
            $base['usage'] = [
                'input_text_tokens' => 12,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 3184,
                'total_tokens' => 10411,
            ];
            $base['completed_at'] = '2026-11-04T14:03:29Z';
        }

        if ($status === 'failed') {
            $base['error'] = [
                'code' => 'internal_error',
                'message' => 'Something went wrong on our side while generating. You were not charged; please retry.',
            ];
            $base['completed_at'] = '2026-11-04T14:03:29Z';
        }

        return array_replace_recursive($base, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(string $code, string $type, string $message, ?string $param = null): array
    {
        return ['error' => array_filter([
            'type' => $type,
            'code' => $code,
            'message' => $message,
            'param' => $param,
        ])];
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(string $type, array $generation, string $id = 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A', string $createdAt = '2026-11-04T14:03:29Z'): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'created_at' => $createdAt,
            'data' => ['object' => $generation],
        ];
    }
}
