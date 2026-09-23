<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => ['user' => $request->user()?->only('id', 'name', 'email')],
            'flash' => Inertia::always(fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ]),
        ];
    }
}
