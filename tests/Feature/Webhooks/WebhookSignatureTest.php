<?php

namespace Tests\Feature\Webhooks;

use Tests\Support\PixelforgeFixtures as F;
use Tests\Support\SendsPixelforgeWebhooks;
use Tests\TestCase;

class WebhookSignatureTest extends TestCase
{
    use SendsPixelforgeWebhooks;

    private function body(): string
    {
        // Deliberately unusual formatting: the signature covers these exact bytes.
        return "{\n  \"id\": \"evt_sig\",  \"object\": \"event\", \"type\": \"ping\", \"created_at\": \"2026-11-04T14:03:29Z\", \"data\": {\"object\": {}}\n}";
    }

    public function test_valid_signature_over_the_raw_body_is_accepted_without_csrf(): void
    {
        $this->postWebhook($this->body(), $this->signature($this->body()))->assertSuccessful();
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->postWebhook($this->body())->assertStatus(400);
    }

    public function test_missing_timestamp_is_rejected(): void
    {
        $header = 'v1='.hash_hmac('sha256', $this->body(), $this->webhookSecret);

        $this->postWebhook($this->body(), $header)->assertStatus(400);
    }

    public function test_expired_timestamp_is_rejected(): void
    {
        $this->postWebhook($this->body(), $this->signature($this->body(), time() - 301))->assertStatus(400);
    }

    public function test_modified_payload_is_rejected(): void
    {
        $signature = $this->signature($this->body());

        $this->postWebhook(str_replace('ping', 'generation.succeeded', $this->body()), $signature)->assertStatus(400);
    }

    public function test_re_encoded_json_is_rejected(): void
    {
        // Same data, different bytes: proves we verify the raw body, not parsed JSON.
        $signature = $this->signature($this->body());
        $reencoded = json_encode(json_decode($this->body(), true));

        $this->postWebhook($reencoded, $signature)->assertStatus(400);
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $this->postWebhook($this->body(), $this->signature($this->body(), secret: 'whsec_other'))->assertStatus(400);
    }

    public function test_unsigned_webhooks_are_rejected_when_no_secret_is_configured(): void
    {
        config(['pixelforge.webhook_secret' => null]);

        $this->postWebhook($this->body(), $this->signature($this->body(), secret: ''))->assertStatus(400);
    }

    public function test_rejection_does_not_leak_the_secret(): void
    {
        $response = $this->postWebhook($this->body(), 't=1,v1='.str_repeat('a', 64));

        $this->assertStringNotContainsString($this->webhookSecret, $response->getContent());
    }

    public function test_get_is_not_allowed(): void
    {
        $this->get('/webhooks/pixelforge')->assertStatus(405);
    }

    public function test_full_event_payload_shape_is_accepted(): void
    {
        $this->sendSignedEvent(F::event('ping', []))->assertSuccessful();
    }
}
