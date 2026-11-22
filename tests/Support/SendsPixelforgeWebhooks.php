<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

trait SendsPixelforgeWebhooks
{
    protected string $webhookSecret = 'whsec_test_secret';

    public function signature(string $rawBody, ?int $timestamp = null, ?string $secret = null): string
    {
        $timestamp ??= time();
        $hmac = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret ?? $this->webhookSecret);

        return "t={$timestamp},v1={$hmac}";
    }

    /**
     * Posts the exact bytes given, like Pixelforge does.
     *
     * @param  array<string, string>  $headers
     */
    public function postWebhook(string $rawBody, ?string $signatureHeader = null, array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_USER_AGENT' => 'Pixelforge-Webhooks/1.0'];

        if ($signatureHeader !== null) {
            $server['HTTP_PIXELFORGE_SIGNATURE'] = $signatureHeader;
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], $server, $rawBody);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function sendSignedEvent(array $event, int $attempt = 1): TestResponse
    {
        $body = json_encode($event, JSON_UNESCAPED_SLASHES);

        return $this->postWebhook($body, $this->signature($body), [
            'Pixelforge-Event-Id' => $event['id'],
            'Pixelforge-Event-Type' => $event['type'],
            'Pixelforge-Delivery-Attempt' => (string) $attempt,
        ]);
    }
}
