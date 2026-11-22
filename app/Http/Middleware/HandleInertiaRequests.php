<?php

namespace App\Http\Middleware;

use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => $user->isAdmin(),
                    'credit_balance' => Money::normalize((string) $user->credit_balance),
                ] : null,
            ],
            // The route list depends on the role (see config/ziggy.php). Sent with
            // every response so it follows sign-in/sign-out, which are Inertia
            // visits and don't reload the page.
            'ziggy' => fn () => (new Ziggy($user?->isAdmin() ? 'admin' : 'member'))->toArray(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }
}
