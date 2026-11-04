<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reset daily token consumption at midnight every day
Schedule::command('passwire:reset-daily-usage')->dailyAt('00:00')->withoutOverlapping();

// Prune historical request logs older than retention period
Schedule::command('passwire:prune-logs')->dailyAt('00:15')->withoutOverlapping();
