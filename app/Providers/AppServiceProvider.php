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
        // Utan egen transporter bygger skannern en Guzzle med timeout. Bind
        // uttryckligen så att containern aldrig skickar in en annan PSR-18-klient.
        $this->app->bind(\App\Services\Glosis\HomeworkScanner::class, fn () => new \App\Services\Glosis\HomeworkScanner);
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

        // Glosis fotoskanning: 60 per timme och IP, utöver gränsen per köp
        // och dygn i GlosisScanController. 429-svarets form sätts i
        // bootstrap/app.php (gäller även den globala API-gränsen).
        RateLimiter::for('glosis-scan', function (Request $request) {
            return Limit::perHour(60)->by('glosis-scan:'.$request->ip());
        });

        RateLimiter::for('score-submit', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
    }
}
