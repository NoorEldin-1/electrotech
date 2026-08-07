<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Slide 5: flag pipeline operations that still have no priced offer, daily.
Schedule::command('sales:notify-incomplete-operations')->dailyAt('08:00');

// Drop API access tokens that expired more than 24h ago. They already cannot
// authenticate anything — Sanctum checks `expires_at` on every request — so
// this is table hygiene: `personal_access_tokens` is read on the hot path of
// every API call and must not grow without bound.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:00');
