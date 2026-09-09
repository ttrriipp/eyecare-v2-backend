<?php

use App\Models\Appointment;
use App\Models\SmsNotification;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-09 08:00:00');
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

test('appointment reminders identify EyeCare in the SMS body', function (): void {
    $appointment = Appointment::factory()->create([
        'scheduled_at' => Carbon::parse('2026-09-10 10:00:00'),
    ]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    $sms = SmsNotification::query()
        ->where('appointment_id', $appointment->id)
        ->where('event', 'appointment_reminder')
        ->sole();

    expect($sms->message)->toStartWith('EyeCare: ');
});
