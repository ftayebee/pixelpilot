<?php

namespace Tests\Unit;

use App\Services\Pixelforge\Webhooks\InvalidWebhookSignature;
use App\Services\Pixelforge\Webhooks\PixelforgeWebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PixelforgeWebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec_unit_secret';

    private const NOW = 1762265009;

    private const BODY = '{"id":"evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A","object":"event","type":"generation.succeeded"}';

    private function verifier(?string $secret = self::SECRET): PixelforgeWebhookVerifier
    {
        return new PixelforgeWebhookVerifier($secret, 300);
    }

    private function header(int $timestamp = self::NOW, string $body = self::BODY, string $secret = self::SECRET): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    public function test_valid_signature_passes(): void
    {
        $this->verifier()->verify(self::BODY, $this->header(), self::NOW);
        $this->addToAssertionCount(1);
    }

    public function test_timestamps_inside_the_window_pass(): void
    {
        $this->verifier()->verify(self::BODY, $this->header(self::NOW - 300), self::NOW);
        $this->verifier()->verify(self::BODY, $this->header(self::NOW + 300), self::NOW);
        $this->addToAssertionCount(2);
    }

    public function test_whitespace_and_multiple_signatures_are_tolerated(): void
    {
        $valid = hash_hmac('sha256', self::NOW.'.'.self::BODY, self::SECRET);
        $header = 't='.self::NOW.', v1='.str_repeat('0', 64).', v1='.strtoupper($valid);

        $this->verifier()->verify(self::BODY, $header, self::NOW);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string|null, ?string, int}>
     */
    public static function invalidCases(): array
    {
        $now = self::NOW;
        $good = fn (int $t = self::NOW, string $body = self::BODY, string $secret = self::SECRET) => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);

        return [
            'missing header' => [null, self::BODY, $now],
            'empty header' => ['', self::BODY, $now],
            'missing timestamp' => ['v1='.hash_hmac('sha256', self::BODY, self::SECRET), self::BODY, $now],
            'non-numeric timestamp' => ['t=abc,v1='.str_repeat('a', 64), self::BODY, $now],
            'missing v1' => ["t={$now}", self::BODY, $now],
            'malformed v1' => ["t={$now},v1=nothex", self::BODY, $now],
            'expired timestamp' => [$good($now - 301), self::BODY, $now],
            'timestamp from the future' => [$good($now + 301), self::BODY, $now],
            'modified payload' => [$good(), str_replace('succeeded', 'failed', self::BODY), $now],
            'one byte of whitespace added' => [$good(), self::BODY.' ', $now],
            'wrong secret' => [$good($now, self::BODY, 'whsec_someone_else'), self::BODY, $now],
            'signature for a different timestamp' => [str_replace("t={$now}", 't='.($now - 1), $good()), self::BODY, $now],
        ];
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_signatures_are_rejected(?string $header, string $body, int $now): void
    {
        $this->expectException(InvalidWebhookSignature::class);

        $this->verifier()->verify($body, $header, $now);
    }

    public function test_unconfigured_secret_rejects_everything(): void
    {
        $this->expectException(InvalidWebhookSignature::class);

        // Even a request "signed" with an empty key must not pass.
        $this->verifier(null)->verify(self::BODY, $this->header(secret: ''), self::NOW);
    }
}
