<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_in_the_app_layout_for_signed_in_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('auth.user.email', $user->email)
                ->where('auth.user.is_admin', false));
    }

    public function test_settings_page_requires_authentication(): void
    {
        $this->get('/settings')->assertRedirect('/login');
    }

    public function test_profile_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => 'New Name', 'email' => 'new@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/settings/password', [
                'current_password' => 'wrong',
                'password' => 'another123',
                'password_confirmation' => 'another123',
            ])->assertSessionHasErrors('current_password');

        $this->actingAs($user)
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'another123',
                'password_confirmation' => 'another123',
            ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('another123', $user->fresh()->password));
    }
}
