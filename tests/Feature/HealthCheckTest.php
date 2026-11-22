<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_reports_status_without_leaking_configuration(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.pixelforge_configured', true);

        $body = $response->getContent();
        $this->assertStringNotContainsString('test_key', $body);
        $this->assertStringNotContainsString('whsec_test_secret', $body);
    }
}
