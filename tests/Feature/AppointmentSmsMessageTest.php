<?php

use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\SmsNotification;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake();
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

it('builds a confirmation message containing the scheduled date', function () {
    $appointment = Appointment::factory()->create();

    $action = new UpdateAppointmentStatus;
    $action->handle($appointment, 'confirmed');

    $sms = SmsNotification::query()->where('appointment_id', $appointment->id)->firstOrFail();

    expect($sms->event)->toBe('appointment_confirmed')
        ->and($sms->message)->toContain($appointment->scheduled_at->toDateTimeString())
        ->and($sms->message)->toContain('confirmed');
});

it('builds a reschedule message containing the new scheduled date', function () {
    $newDate = now()->addDays(5);
    $appointment = Appointment::factory()->create();

    $action = new UpdateAppointmentStatus;
    $action->handle($appointment, 'rescheduled', scheduledAt: $newDate);

    $sms = SmsNotification::query()->where('appointment_id', $appointment->id)->firstOrFail();

    expect($sms->event)->toBe('appointment_rescheduled')
        ->and($sms->message)->toContain($newDate->toDateTimeString())
        ->and($sms->message)->toContain('rescheduled');
});

it('builds a cancellation message containing the scheduled date', function () {
    $appointment = Appointment::factory()->create();

    $action = new UpdateAppointmentStatus;
    $action->handle($appointment, 'cancelled');

    $sms = SmsNotification::query()->where('appointment_id', $appointment->id)->firstOrFail();

    expect($sms->event)->toBe('appointment_cancelled')
        ->and($sms->message)->toContain($appointment->scheduled_at->toDateTimeString())
        ->and($sms->message)->toContain('cancelled');
});

it('does not create an sms record for the completed status', function () {
    $confirmedStatus = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $confirmedStatus->id,
    ]);

    $action = new UpdateAppointmentStatus;
    $action->handle($appointment, 'completed');

    expect(SmsNotification::query()->where('appointment_id', $appointment->id)->count())->toBe(0);
});

it('sets recipient to phone when available and falls back to email', function () {
    $appointmentWithPhone = Appointment::factory()
        ->for(User::factory()->customer()->create(['phone' => '+639171234567']), 'customer')
        ->create();

    $appointmentNoPhone = Appointment::factory()
        ->for(User::factory()->customer()->create(['phone' => null]), 'customer')
        ->create();

    $action = new UpdateAppointmentStatus;
    $action->handle($appointmentWithPhone, 'confirmed');
    $action->handle($appointmentNoPhone, 'confirmed');

    $smsWithPhone = SmsNotification::query()->where('appointment_id', $appointmentWithPhone->id)->firstOrFail();
    $smsNoPhone = SmsNotification::query()->where('appointment_id', $appointmentNoPhone->id)->firstOrFail();

    expect($smsWithPhone->recipient)->toBe('+639171234567')
        ->and($smsNoPhone->recipient)->toBe($appointmentNoPhone->customer->email);
});

it('does not make any real http calls during sms record creation', function () {
    $appointment = Appointment::factory()->create();

    $action = new UpdateAppointmentStatus;
    $action->handle($appointment, 'confirmed');

    Http::assertNothingSent();
});
