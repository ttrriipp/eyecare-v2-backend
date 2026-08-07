<?php

use App\Actions\Appointments\AcceptAppointmentRequest;
use App\Actions\Appointments\RejectAppointmentRequest;
use App\Enums\AppointmentRequestStatus;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    // AcceptAppointmentRequest re-checks availability against the chosen
    // appointment type's real duration, which needs an optometrist with
    // provider hours covering the slot (auto-created for every weekday).
    User::factory()->optometrist()->create();
});

afterEach(fn () => Carbon::setTestNow());

// --- Accept ---

test('accepting a request creates a scheduled appointment', function () {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();

    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'patient_id' => $user->patient->id,
        'status' => AppointmentRequestStatus::Pending,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $appointment = app(AcceptAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
    );

    expect($appointment)->toBeInstanceOf(Appointment::class)
        ->and($appointment->status->name)->toBe('scheduled')
        ->and($appointment->patient_id)->toBe($user->patient->id);

    expect($request->fresh()->status)->toBe(AppointmentRequestStatus::Accepted)
        ->and($request->fresh()->appointment_id)->toBe($appointment->id);
});

test('accepting is idempotent - returns same appointment on repeat', function () {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();

    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'patient_id' => $user->patient->id,
        'status' => AppointmentRequestStatus::Pending,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $first = app(AcceptAppointmentRequest::class)->handle($request, $reviewer);
    $second = app(AcceptAppointmentRequest::class)->handle($request->fresh(), $reviewer);

    expect($first->id)->toBe($second->id);
});

test('accepting requires a resolved patient', function () {
    $reviewer = User::factory()->staff()->create();

    $request = AppointmentRequest::factory()->create([
        'patient_id' => null, // Unlinked
        'status' => AppointmentRequestStatus::Pending,
    ]);

    expect(fn () => app(AcceptAppointmentRequest::class)->handle($request, $reviewer))
        ->toThrow(ValidationException::class);
});

test('accepting copies reason for visit', function () {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();

    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'patient_id' => $user->patient->id,
        'status' => AppointmentRequestStatus::Pending,
        'encrypted_reason_for_visit' => 'Blurred vision',
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $appointment = app(AcceptAppointmentRequest::class)->handle($request, $reviewer);

    expect($appointment->reason_for_visit)->toBe('Blurred vision');
});

test('accepting rejects a type whose real duration now conflicts with another appointment', function () {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();

    // The mobile request only held a provisional 30-minute slot at 10:00.
    // Since then, another appointment was booked into 10:15-10:30 with the
    // clinic's only optometrist — a 30-minute type starting at 10:00 would
    // now overlap it, even though the original hold didn't conflict.
    Appointment::factory()->create([
        'scheduled_at' => '2026-07-13 10:15:00',
        'duration_minutes' => 15,
    ]);

    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'patient_id' => $user->patient->id,
        'status' => AppointmentRequestStatus::Pending,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $routineCheckup = AppointmentType::where('name', 'Routine Check-up')->firstOrFail();

    expect(fn () => app(AcceptAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        appointmentTypeId: $routineCheckup->id,
    ))->toThrow(ValidationException::class);

    expect($request->fresh()->status)->toBe(AppointmentRequestStatus::Pending);
    $this->assertDatabaseCount('appointments', 1);
});

// --- Reject ---

test('rejecting closes the request without creating appointment', function () {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();

    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'status' => AppointmentRequestStatus::Pending,
    ]);

    $result = app(RejectAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        reason: 'No available slots',
    );

    expect($result->status)->toBe(AppointmentRequestStatus::Rejected)
        ->and($result->resolved_by_user_id)->toBe($reviewer->id);

    $this->assertDatabaseCount('appointments', 0);
});
