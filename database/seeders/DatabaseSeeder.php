<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds a demo account for local development only. Production never gets
     * an account with a known password; create real users through sign-up
     * and promote an operator with `php artisan pixelpilot:make-admin`.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('Skipping demo user outside local/testing environments.');

            return;
        }

        $password = env('DEMO_USER_PASSWORD') ?: Str::password(16, symbols: false);

        $user = User::updateOrCreate(
            ['email' => 'demo@pixelpilot.test'],
            ['name' => 'Demo Pilot', 'password' => $password, 'email_verified_at' => now()],
        );
        $user->forceFill(['is_admin' => true])->save();

        $this->command?->info("Demo admin: demo@pixelpilot.test / {$password}");
    }
}
