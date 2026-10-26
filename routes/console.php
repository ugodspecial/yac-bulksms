<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled / recurring work
|--------------------------------------------------------------------------
|
| Add this single cron entry on the server:
|
|   * * * * * cd /path/to/yac-bulksms && php artisan schedule:run >> /dev/null 2>&1
|
*/

// Queue scheduled campaigns as soon as they are due.
Schedule::command('campaigns:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping(10);

// Poll Termii for delivery reports and flag interrupted sends.
Schedule::command('campaigns:reconcile')
    ->everyTenMinutes()
    ->withoutOverlapping(15);

// Keep the shared provider balance reasonably fresh for the dashboard.
Schedule::command('termii:balance')
    ->hourlyAt(10)
    ->withoutOverlapping(10);

// Retention for operational logs.
Schedule::command('logs:prune')
    ->dailyAt('02:30')
    ->withoutOverlapping(30);
