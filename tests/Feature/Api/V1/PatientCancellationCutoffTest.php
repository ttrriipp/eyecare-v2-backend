<?php

use App\Actions\Appointments\CancelAppointment;
use App\Enums\AppointmentRequestStatus;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', config('app.timezone')));

    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

test('patient cannot cancel an appointment on its scheduled day in the app timezone', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-10 00:30:00', config('app.timezone')));

    $patient = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->patient->id,
        'scheduled_at' => Carbon::parse('2026-07-10 23:30:00', config('app.timezone')),
    ]);

    $this->actingAs($patient)
        ->postJson("/api/v1/appointments/{$appointment->id}/cancel", [
            'reason_details' => 'My plans changed.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment']);

    expect($appointment->fresh()->status->name)->toBe('scheduled');
});

test('patient can cancel an appointment scheduled for a later day', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-10 23:30:00', config('app.timezone')));

    $patient = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->patient->id,
        'scheduled_at' => Carbon::parse('2026-07-11 00:15:00', config('app.timezone')),
    ]);

    $this->actingAs($patient)
        ->postJson("/api/v1/appointments/{$appointment->id}/cancel", [
            'reason_details' => 'My plans changed.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation.reason_details', 'My plans changed.');

    expect($appointment->fresh()->status->name)->toBe('cancelled')
        ->and($appointment->fresh()->cancellation_reason_details)->toBe('My plans changed.');
});

test('patient must provide a reason to cancel a confirmed appointment', function (): void {
    $patient = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->patient->id,
        'scheduled_at' => Carbon::parse('2026-07-11 10:00:00', config('app.timezone')),
    ]);

    $this->actingAs($patient)
        ->postJson("/api/v1/appointments/{$appointment->id}/cancel")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason_details']);

    expect($appointment->fresh()->status->name)->toBe('scheduled');
});

test('patient cancellation category is assigned by the backend', function (): void {
    $patient = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->patient->id,
        'scheduled_at' => Carbon::parse('2026-07-11 10:00:00', config('app.timezone')),
    ]);

    $this->actingAs($patient)
        ->postJson("/api/v1/appointments/{$appointment->id}/cancel", [
            'reason_category' => 'medical_reason',
            'reason_details' => 'I can no longer attend.',
        ])
        ->assertOk();

    expect($appointment->fresh()->status->name)->toBe('cancelled')
        ->and($appointment->fresh()->cancellation_reason_category)->toBe('patient_request')
        ->and($appointment->fresh()->cancellation_reason_details)->toBe('I can no longer attend.');
});

test('clinic staff can still cancel an appointment scheduled for today', function (): void {
    $patient = User::factory()->patient()->create();
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->patient->id,
        'scheduled_at' => Carbon::parse('2026-07-10 10:00:00', config('app.timezone')),
    ]);

    $cancelledAppointment = app(CancelAppointment::class)->handle(
        appointment: $appointment,
        initiator: 'clinic',
        actor: $staff,
        reasonCategory: 'schedule_conflict',
    );

    expect($cancelledAppointment->status->name)->toBe('cancelled');
});

test('patient cannot cancel a pending appointment request scheduled for today', function (): void {
    $patient = User::factory()->patient()->create();
    $request = AppointmentRequest::factory()->create([
        'user_id' => $patient->id,
        'scheduled_at' => Carbon::parse('2026-07-10 10:00:00', config('app.timezone')),
    ]);

    $this->actingAs($patient)
        ->postJson("/api/v1/appointment-requests/{$request->id}/cancel", [
            'reason_details' => 'I no longer need the appointment.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['request']);

    expect($request->fresh()->status)->toBe(AppointmentRequestStatus::Pending);
});

test('patient can cancel a pending appointment request scheduled for a later day', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-10 23:30:00', config('app.timezone')));

    $patient = User::factory()->patient()->create();
    $request = AppointmentRequest::factory()->create([
        'user_id' => $patient->id,
        'scheduled_at' => Carbon::parse('2026-07-11 00:15:00', config('app.timezone')),
    ]);

    $this->actingAs($patient)
        ->postJson("/api/v1/appointment-requests/{$request->id}/cancel", [
            'reason_details' => 'I no longer need the appointment.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', AppointmentRequestStatus::Cancelled->value)
        ->assertJsonPath('data.cancellation_reason', 'I no longer need the appointment.');

    $storedReason = DB::table('appointment_requests')
        ->where('id', $request->id)
        ->value('encrypted_cancellation_reason');

    expect($request->fresh()->status)->toBe(AppointmentRequestStatus::Cancelled)
        ->and($request->fresh()->encrypted_cancellation_reason)->toBe('I no longer need the appointment.')
        ->and($storedReason)->not->toContain('I no longer need the appointment.');
});

test('patient must provide a reason to cancel a pending appointment request', function (): void {
    $patient = User::factory()->patient()->create();
    $request = AppointmentRequest::factory()->create([
        'user_id' => $patient->id,
        'scheduled_at' => Carbon::parse('2026-07-11 10:00:00', config('app.timezone')),
    ]);

    $this->actingAs($patient)
        ->postJson("/api/v1/appointment-requests/{$request->id}/cancel")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason_details']);

    expect($request->fresh()->status)->toBe(AppointmentRequestStatus::Pending);
});
