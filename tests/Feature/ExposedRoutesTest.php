<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExposedRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_internal_routes_are_not_listed_for_guests_or_members(): void
    {
        $guest = $this->get('/login')->getContent();
        $member = $this->actingAs(User::factory()->create())->get('/dashboard')->getContent();

        foreach ([$guest, $member] as $html) {
            $this->assertStringNotContainsString('"admin.analytics"', $html);
            $this->assertStringNotContainsString('"webhooks.pixelforge"', $html);
            $this->assertStringContainsString('"generations.create"', $html);
        }
    }

    public function test_admins_get_the_admin_routes(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $html = $this->actingAs($admin)->get('/dashboard')->getContent();

        $this->assertStringContainsString('"admin.analytics"', $html);
        $this->assertStringNotContainsString('"webhooks.pixelforge"', $html);
    }
}
