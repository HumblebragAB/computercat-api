<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Intresseanmälan är publik och utan inloggning. Gränsen per adress
        // är det egentliga skyddet mot att spamma någon. Gränsen per IP är
        // generös med flit: spelens webbplatser (glosis.se) anropar
        // server-till-server, så alla deras besökare delar en IP, och varje
        // webbplats begränsar sina besökare själv.
        RateLimiter::for('interest', function (Request $request) {
            return [
                Limit::perMinute(60)->by($request->ip()),
                Limit::perHour(3)->by('interest:'.mb_strtolower((string) $request->input('email'))),
            ];
        });

        RateLimiter::for('score-submit', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
    }
}
