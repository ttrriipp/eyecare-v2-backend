<?php

use App\Actions\Appointments\CreateWalkInAppointment;
use App\Actions\Encounters\CheckInAppointment;
use App\Enums\EncounterStatus;
use App\Enums\IntakeStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\PatientIntake;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
});

test('check-in creates exactly one encounter for the appointment', function () {
    $appointment = Appointment::factory()->create();
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter)->toBeInstanceOf(Encounter::class)
        ->and($encounter->patient_id)->toBe($appointment->patient_id)
        ->and($encounter->appointment_id)->toBe($appointment->id)
        ->and($encounter->status)->toBe(EncounterStatus::Planned);

    $this->assertDatabaseCount('encounters', 1);
});

test('check-in updates appointment status to arrived', function () {
    $appointment = Appointment::factory()->create();
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    app(CheckInAppointment::class)->handle($appointment);

    $appointment->refresh();
    expect($appointment->status->name)->toBe('checked_in')
        ->and($appointment->checked_in_at)->not->toBeNull()
        ->and($appointment->checked_in_by)->toBe($staff->id);
});

test('cancelled appointments cannot create encounters', function () {
    $cancelled = AppointmentStatus::query()->where('name', 'cancelled')->firstOrFail();
    $appointment = Appointment::factory()->create(['appointment_status_id' => $cancelled->id]);
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    app(CheckInAppointment::class)->handle($appointment);
})->throws(ValidationException::class);

test('no-show appointments cannot create encounters', function () {
    $noShow = AppointmentStatus::query()->where('name', 'no_show')->firstOrFail();
    $appointment = Appointment::factory()->create(['appointment_status_id' => $noShow->id]);
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    app(CheckInAppointment::class)->handle($appointment);
})->throws(ValidationException::class);

test('check-in returns existing encounter when appointment already has one', function () {
    $appointment = Appointment::factory()->create();
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    // Create an encounter with the same appointment_id to simulate already-checked-in
    $existingEncounter = Encounter::factory()->create(['appointment_id' => $appointment->id]);

    // Check-in should return the existing encounter, not throw
    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->id)->toBe($existingEncounter->id);
    $this->assertDatabaseCount('encounters', 1);
});

test('concurrent check-in cannot create duplicate encounters', function () {
    $appointment = Appointment::factory()->create();
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    app(CheckInAppointment::class)->handle($appointment);

    // Second check-in should fail because appointment is already 'arrived'
    app(CheckInAppointment::class)->handle($appointment->fresh());
})->throws(ValidationException::class);

test('encounter factory produces valid records', function () {
    $waiting = Encounter::factory()->create();
    $inProgress = Encounter::factory()->inProgress()->create();
    $completed = Encounter::factory()->completed()->create();

    expect($waiting->status)->toBe(EncounterStatus::Planned)
        ->and($waiting->encounter_number)->toStartWith('ENC-')
        ->and($inProgress->status)->toBe(EncounterStatus::InProgress)
        ->and($inProgress->started_at)->not->toBeNull()
        ->and($completed->status)->toBe(EncounterStatus::Completed)
        ->and($completed->completed_at)->not->toBeNull();
});

test('check-in snapshots the verified intake on the encounter', function () {
    $appointment = Appointment::factory()->create();
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    // Create a verified intake for this patient/appointment
    $intake = PatientIntake::factory()->verified()->create([
        'patient_id' => $appointment->patient_id,
        'appointment_id' => $appointment->id,
        'status' => IntakeStatus::Verified,
    ]);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->patient_intake_id)->toBe($intake->id)
        ->and($encounter->intake->id)->toBe($intake->id);
});

test('check-in works without a verified intake', function () {
    $appointment = Appointment::factory()->create();
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->patient_intake_id)->toBeNull();
});

test('walk-in appointment creation creates an encounter', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $appointmentType = AppointmentType::query()->first();

    $appointment = app(CreateWalkInAppointment::class)->handle(
        patient: $patient,
        appointmentType: $appointmentType,
        staff: $staff,
    );

    expect($appointment->status->name)->toBe('checked_in')
        ->and($appointment->encounter)->not->toBeNull()
        ->and($appointment->encounter->status)->toBe(EncounterStatus::Planned);

    $this->assertDatabaseCount('encounters', 1);
});
