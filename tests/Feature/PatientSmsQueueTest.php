<?php

use App\Actions\Sms\QueuePatientSms;
use App\Models\Appointment;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\SmsNotification;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

test('queues an appointment SMS for a patient phone number', function (): void {
    $patient = Patient::factory()->create(['phone' => '09171234567']);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

    $sms = app(QueuePatientSms::class)->handle(
        patient: $patient,
        event: 'appointment_cancelled',
        message: "Your appointment {$appointment->appointment_number} was cancelled.",
        appointment: $appointment,
    );

    expect($sms)->toBeInstanceOf(SmsNotification::class)
        ->and($sms->fresh()->appointment_id)->toBe($appointment->id)
        ->and($sms->fresh()->job_order_id)->toBeNull()
        ->and($sms->fresh()->recipient)->toBe('+639171234567')
        ->and($sms->fresh()->message)->toStartWith('EyeCare: ')
        ->and($sms->fresh()->status->name)->toBe('queued');
});

test('queues an optical order SMS with its canonical job order link', function (): void {
    $patient = Patient::factory()->create(['phone' => '09171234567']);
    $order = JobOrder::factory()->create(['patient_id' => $patient->id]);

    $sms = app(QueuePatientSms::class)->handle(
        patient: $patient,
        event: 'optical_order_ready',
        message: "Your optical order {$order->job_order_number} is ready for pickup.",
        jobOrder: $order,
    );

    expect($sms)->toBeInstanceOf(SmsNotification::class)
        ->and($sms->fresh()->appointment_id)->toBeNull()
        ->and($sms->fresh()->job_order_id)->toBe($order->id)
        ->and($sms->fresh()->recipient)->toBe('+639171234567')
        ->and($sms->fresh()->message)->toStartWith('EyeCare: ')
        ->and($sms->fresh()->status->name)->toBe('queued');
});

test('does not queue an SMS when the patient has no phone number', function (): void {
    $patient = Patient::factory()->create(['phone' => null]);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

    $sms = app(QueuePatientSms::class)->handle(
        patient: $patient,
        event: 'appointment_cancelled',
        message: 'This message should not be queued.',
        appointment: $appointment,
    );

    expect($sms)->toBeNull()
        ->and(SmsNotification::query()->count())->toBe(0);
});
