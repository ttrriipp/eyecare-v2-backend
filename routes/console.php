<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('appointments:send-reminders')
    ->everyMinute()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

$hasSmsReminderTestConfiguration = static function (): bool {
    $appointmentId = filter_var(
        config('services.sms_reminder_test.appointment_id'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]],
    );
    $recipient = config('services.sms_reminder_test.recipient');

    return $appointmentId !== false
        && is_string($recipient)
        && preg_match('/^\+[1-9]\d{7,14}$/', $recipient) === 1;
};

Schedule::command('sms:process')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(static function () use ($hasSmsReminderTestConfiguration): void {
    if (! $hasSmsReminderTestConfiguration()) {
        return;
    }

    Artisan::call('appointments:send-reminders', [
        '--test-appointment' => (int) config('services.sms_reminder_test.appointment_id'),
        '--test-recipient' => config('services.sms_reminder_test.recipient'),
        '--scheduled-test' => true,
    ]);
})
    ->everyMinute()
    ->timezone(config('app.timezone'))
    ->name('sms-reminder-scheduler-test')
    ->when($hasSmsReminderTestConfiguration)
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('sms:textbee:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('clinic:daily-summary')->dailyAt('21:00');
Schedule::command('appointments:expire-requests')->everyMinute()->withoutOverlapping();
Schedule::command('accessory-orders:expire-unpaid')->everyMinute()->withoutOverlapping();
Schedule::command('patient-accounts:prune')->dailyAt('03:00')->withoutOverlapping();
