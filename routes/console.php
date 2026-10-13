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
Schedule::command('computercat:prune-oauth-clients --hours=24')->dailyAt('04:30');
