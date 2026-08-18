<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('appointments:send-reminders')
    ->dailyAt('09:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
Schedule::command('sms:process')->everyMinute()->withoutOverlapping();
Schedule::command('clinic:daily-summary')->dailyAt('21:00');
Schedule::command('appointments:expire-requests')->everyMinute()->withoutOverlapping();
Schedule::command('reservations:expire')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('patient-accounts:prune')->dailyAt('03:00')->withoutOverlapping();
