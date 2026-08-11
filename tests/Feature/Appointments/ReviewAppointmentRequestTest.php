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
    $this->optometrist = User::factory()->optometrist()->create();
    $this->appointmentType = AppointmentType::where('name', 'New Patient')->first();
});

afterEach(fn () => Carbon::setTestNow());

// --- Accept ---

test('accepting a request creates a scheduled appointment with provider', function () {
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
        appointmentType: $this->appointmentType,
        durationMinutes: $this->appointmentType->duration_minutes,
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        optometrist: $this->optometrist,
    );

    expect($appointment)->toBeInstanceOf(Appointment::class)
        ->and($appointment->status->name)->toBe('scheduled')
        ->and($appointment->patient_id)->toBe($user->patient->id)
        ->and($appointment->optometrist_id)->toBe($this->optometrist->id)
        ->and($appointment->duration_minutes)->toBe(45);

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

    $first = app(AcceptAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        appointmentType: $this->appointmentType,
        durationMinutes: $this->appointmentType->duration_minutes,
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        optometrist: $this->optometrist,
    );

    $second = app(AcceptAppointmentRequest::class)->handle(
        request: $request->fresh(),
        reviewer: $reviewer,
        appointmentType: $this->appointmentType,
        durationMinutes: $this->appointmentType->duration_minutes,
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        optometrist: $this->optometrist,
    );

    expect($first->id)->toBe($second->id);
});

test('accepting requires a resolved patient', function () {
    $reviewer = User::factory()->staff()->create();

    $request = AppointmentRequest::factory()->create([
        'patient_id' => null, // Unlinked
        'status' => AppointmentRequestStatus::Pending,
    ]);

    expect(fn () => app(AcceptAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        appointmentType: $this->appointmentType,
        durationMinutes: $this->appointmentType->duration_minutes,
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        optometrist: $this->optometrist,
    ))->toThrow(ValidationException::class);
});

test('accepting requires an active optometrist', function () {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();

    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'patient_id' => $user->patient->id,
        'status' => AppointmentRequestStatus::Pending,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $inactiveOptometrist = User::factory()->optometrist()->create(['is_active' => false]);

    expect(fn () => app(AcceptAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        appointmentType: $this->appointmentType,
        durationMinutes: $this->appointmentType->duration_minutes,
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        optometrist: $inactiveOptometrist,
    ))->toThrow(ValidationException::class);
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

    $appointment = app(AcceptAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        appointmentType: $this->appointmentType,
        durationMinutes: $this->appointmentType->duration_minutes,
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        optometrist: $this->optometrist,
    );

    expect($appointment->reason_for_visit)->toBe('Blurred vision');
});

test('accepting rejects a type whose real duration now conflicts with another appointment', function () {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();

    // Create an existing appointment at 10:15 with the same optometrist
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'scheduled_at' => '2026-07-13 10:15:00',
        'duration_minutes' => 15,
    ]);

    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'patient_id' => $user->patient->id,
        'status' => AppointmentRequestStatus::Pending,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    expect(fn () => app(AcceptAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        appointmentType: $this->appointmentType,
        durationMinutes: $this->appointmentType->duration_minutes,
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        optometrist: $this->optometrist,
    ))->toThrow(ValidationException::class);

    expect($request->fresh()->status)->toBe(AppointmentRequestStatus::Pending);
    $this->assertDatabaseCount('appointments', 1);
});

test('accepting requires referral source when type requires it', function () {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();

    $referralType = AppointmentType::where('name', 'Referral')->first();

    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'patient_id' => $user->patient->id,
        'status' => AppointmentRequestStatus::Pending,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    expect(fn () => app(AcceptAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        appointmentType: $referralType,
        durationMinutes: $referralType->duration_minutes,
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        optometrist: $this->optometrist,
    ))->toThrow(ValidationException::class);
});

test('accepting copies referral source when provided', function () {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();

    $referralType = AppointmentType::where('name', 'Referral')->first();

    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'patient_id' => $user->patient->id,
        'status' => AppointmentRequestStatus::Pending,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $appointment = app(AcceptAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        appointmentType: $referralType,
        durationMinutes: $referralType->duration_minutes,
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        optometrist: $this->optometrist,
        referringSource: 'Dr. Garcia - City Hospital',
    );

    expect($appointment->referring_source)->toBe('Dr. Garcia - City Hospital');
});

test('accepting outside submitted preferences requires a contact note', function () {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();

    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'patient_id' => $user->patient->id,
        'status' => AppointmentRequestStatus::Pending,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    expect(fn () => app(AcceptAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        appointmentType: $this->appointmentType,
        durationMinutes: $this->appointmentType->duration_minutes,
        scheduledAt: Carbon::parse('2026-07-13 11:00:00'),
        optometrist: $this->optometrist,
    ))->toThrow(ValidationException::class);

    expect($request->fresh()->status)->toBe(AppointmentRequestStatus::Pending);
});

test('accepting outside submitted preferences succeeds with a contact note', function () {
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
        appointmentType: $this->appointmentType,
        durationMinutes: $this->appointmentType->duration_minutes,
        scheduledAt: Carbon::parse('2026-07-13 11:00:00'),
        optometrist: $this->optometrist,
        contactNote: 'Patient confirmed the alternate time by phone.',
    );

    expect($appointment)->toBeInstanceOf(Appointment::class)
        ->and($appointment->scheduled_at->format('H:i'))->toBe('11:00');
});

test('accepting rejects an inactive appointment type', function () {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();
    $inactiveType = AppointmentType::factory()->inactive()->create(['duration_minutes' => 30]);
    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'patient_id' => $user->patient->id,
        'status' => AppointmentRequestStatus::Pending,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    expect(fn () => app(AcceptAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        appointmentType: $inactiveType,
        durationMinutes: 30,
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        optometrist: $this->optometrist,
    ))->toThrow(ValidationException::class);
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
