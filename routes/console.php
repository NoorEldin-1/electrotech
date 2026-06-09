<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Slide 5: flag pipeline operations that still have no priced offer, daily.
Schedule::command('sales:notify-incomplete-operations')->dailyAt('08:00');
