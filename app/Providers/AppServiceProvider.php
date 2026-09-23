<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        DevCommands::artisan('serve --host=127.0.0.1 --port=2017', 'server');
        DevCommands::artisan('queue:listen --tries=1 --timeout=90', 'queue');
        DevCommands::artisan('schedule:work', 'schedule');

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(30)->by($request->ip()),
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);
    }
}
