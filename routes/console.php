<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly database backup. Runs only where the scheduler cron is active
// (`php artisan schedule:run` every minute); a no-op on non-pgsql connections.
Schedule::command('backup:database')->dailyAt('02:00')->withoutOverlapping();
