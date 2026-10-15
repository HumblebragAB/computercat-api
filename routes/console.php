<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prune stale anonymous accounts weekly (Sunday 3 AM)
Schedule::command('users:prune-anonymous --days=90')->weeklyOn(0, '03:00');

// Obekräftade intresseanmälningar sparas inte längre än 30 dagar (GDPR).
Schedule::command('interest:prune-unconfirmed --days=30')->dailyAt('03:30');

// Glosis gratisnivå (planen 2026-10-12): invarianter, gallring av App Attest-nycklar (13 månader) och utgångna cacherader.
Schedule::command('glosis:check-free-usage')->dailyAt('06:00');
Schedule::command('glosis:prune-attest-keys --months=13')->monthlyOn(1, '04:00');
Schedule::command('glosis:prune-cache')->hourly()->withoutOverlapping(10);

// Övergivna DCR-registreringar (POST /oauth/register): klienter utan tokens eller koder, äldre än 24 h.
// Schemalägg aldrig passport:purge utan --revoked: rensade utgångna access-tokens vars refresh-tokens
// fortfarande gäller får aktiva klienter att se oanvända ut, och då raderas de här.
Schedule::command('computercat:prune-oauth-clients --hours=24')->dailyAt('04:30');

// Sanctum-token som gått ut (90 dagar, eller 10 minuter efter en förnyelse) raderas efter ett dygn.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('04:45');

// Provisioneringens invariant (spec 2c): lyckade Demo-spel mot GitHub, Coolify, SiteOps och Demo-adressen.
Schedule::command('computercat:check-provisioning')->dailyAt('06:15')->withoutOverlapping(30);

// Släppflödet (spec 2026-10-14, spärr 1): kvitton på lyckade Demo-byggen, eftersom Coolifys lista bara visar de senaste.
Schedule::command('computercat:poll-demo-builds')->everyFiveMinutes()->withoutOverlapping(10);

// Släppflödet etapp 2: reserv för TestFlight-jobben (plan R11).
Schedule::command('computercat:advance-releases')->everyFiveMinutes()->withoutOverlapping(10);

// Släppflödet etapp 2: "Computercat ser själv när appen finns" och kör de automatiska App Store-stegen.
Schedule::command('computercat:queue-app-store-setup')->everyTenMinutes()->withoutOverlapping(10);
