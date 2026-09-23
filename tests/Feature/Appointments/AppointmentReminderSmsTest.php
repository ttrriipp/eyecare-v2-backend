<?php

use App\Jobs\SendSmsJob;
use App\Models\Appointment;
use App\Models\ClinicHour;
use App\Models\Patient;
use App\Models\SmsNotification;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-09 08:00:00');
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

test('appointment reminders are queued around 24 hours before the appointment', function (): void {
    $appointment = Appointment::factory()->create([
        'scheduled_at' => Carbon::parse('2026-09-10 08:00:00'),
    ]);
    $laterAppointment = Appointment::factory()->create([
        'scheduled_at' => Carbon::parse('2026-09-10 10:00:00'),
    ]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    $sms = SmsNotification::query()
        ->where('appointment_id', $appointment->id)
        ->where('event', 'appointment_reminder')
        ->sole();

    expect($sms->message)->toStartWith('EyeCare: ')
        ->and(SmsNotification::query()
            ->where('appointment_id', $laterAppointment->id)
            ->where('event', 'appointment_reminder')
            ->exists())->toBeFalse();

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    expect(SmsNotification::query()
        ->where('appointment_id', $appointment->id)
        ->where('event', 'appointment_reminder')
        ->count())->toBe(1);
});

test('five-minute reminder tests require a recipient and queue only the selected appointment', function (): void {
    $appointment = Appointment::factory()->create([
        'scheduled_at' => Carbon::parse('2026-09-09 08:05:00'),
    ]);
    $otherAppointment = Appointment::factory()->create([
        'scheduled_at' => Carbon::parse('2026-09-09 08:05:00'),
    ]);

    Queue::fake();

    $this->artisan('appointments:send-reminders', [
        '--test-appointment' => $appointment->id,
    ])->assertFailed();

    expect(SmsNotification::query()
        ->where('appointment_id', $appointment->id)
        ->where('event', 'appointment_reminder')
        ->exists())->toBeFalse();

    $this->artisan('appointments:send-reminders', [
        '--test-appointment' => $appointment->id,
        '--test-recipient' => '+639171234567',
    ])->assertSuccessful();

    $sms = SmsNotification::query()
        ->where('appointment_id', $appointment->id)
        ->where('event', 'appointment_reminder')
        ->sole();

    expect($sms->recipient)->toBe('+639171234567')
        ->and(SmsNotification::query()
            ->where('appointment_id', $otherAppointment->id)
            ->where('event', 'appointment_reminder')
            ->exists())->toBeFalse();

    Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job): bool => $job->sms->is($sms));
});

test('temporary SMS test appointments require explicit confirmation and do not send a booking SMS', function (): void {
    Carbon::setTestNow('2026-09-09 10:00:00');
    ClinicHour::factory()->forWeekday(now()->dayOfWeek)->create();
    User::factory()->optometrist()->create();

    $this->artisan('appointments:create-sms-test')->assertFailed();

    expect(Patient::query()->count())->toBe(0)
        ->and(Appointment::query()->count())->toBe(0);

    $this->artisan('appointments:create-sms-test', ['--force' => true])->assertSuccessful();

    $appointment = Appointment::query()
        ->with(['patient', 'status'])
        ->where('source', 'sms_test')
        ->sole();

    expect($appointment->scheduled_at->equalTo(Carbon::parse('2026-09-09 10:07:00')))->toBeTrue()
        ->and($appointment->status->name)->toBe('scheduled')
        ->and($appointment->patient->first_name)->toBe('SMS Reminder Test')
        ->and($appointment->patient->phone)->toBeNull()
        ->and(SmsNotification::query()->exists())->toBeFalse();
});
