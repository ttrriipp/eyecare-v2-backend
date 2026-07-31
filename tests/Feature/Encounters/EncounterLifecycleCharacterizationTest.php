<?php

use App\Actions\Encounters\CheckInAppointment;
use App\Actions\Encounters\CompleteEncounter;
use App\Actions\Encounters\StartEncounter;
use App\Enums\EncounterStatus;
use App\Enums\IntakeStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Encounter;
use App\Models\PatientIntake;
use App\Models\Prescription;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->optometrist = User::factory()->optometrist()->create();
    $this->staff = User::factory()->staff()->create();
});

// --- Check-in Behavior ---

test('check-in creates exactly one planned encounter', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter)->toBeInstanceOf(Encounter::class)
        ->and($encounter->status)->toBe(EncounterStatus::Planned)
        ->and($encounter->patient_id)->toBe($appointment->patient_id)
        ->and($encounter->appointment_id)->toBe($appointment->id);

    $this->assertDatabaseCount('encounters', 1);
});

test('check-in updates appointment to checked_in', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    app(CheckInAppointment::class)->handle($appointment);

    $appointment->refresh();
    expect($appointment->status->name)->toBe('checked_in')
        ->and($appointment->checked_in_at)->not->toBeNull()
        ->and($appointment->checked_in_by)->toBe($this->staff->id);
});

test('check-in rejects already checked-in appointments', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    app(CheckInAppointment::class)->handle($appointment);

    // Second check-in fails because appointment is now 'checked_in', not 'scheduled'
    app(CheckInAppointment::class)->handle($appointment->fresh());
})->throws(ValidationException::class);

test('cancelled appointments cannot be checked in', function () {
    $cancelled = AppointmentStatus::query()->where('name', 'cancelled')->firstOrFail();
    $appointment = Appointment::factory()->create(['appointment_status_id' => $cancelled->id]);
    $this->actingAs($this->staff);

    app(CheckInAppointment::class)->handle($appointment);
})->throws(ValidationException::class);

// --- Intake Snapshot ---

test('check-in snapshots verified intake on the encounter', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $intake = PatientIntake::factory()->verified()->create([
        'patient_id' => $appointment->patient_id,
        'appointment_id' => $appointment->id,
        'status' => IntakeStatus::Verified,
    ]);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->patient_intake_id)->toBe($intake->id);
});

test('check-in works without a verified intake', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->patient_intake_id)->toBeNull();
});

// --- Encounter Status Transitions ---

test('start encounter transitions to in_progress', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter,
        optometrist: $this->optometrist,
        actor: $this->optometrist,
    );

    expect($encounter->status)->toBe(EncounterStatus::InProgress)
        ->and($encounter->started_at)->not->toBeNull()
        ->and($encounter->optometrist_id)->toBe($this->optometrist->id);
});

test('start encounter currently fulfills the appointment', function () {
    // NOTE: This behavior will change in the new spec.
    // Currently, StartEncounter marks the appointment as fulfilled.
    // In the new spec, the appointment stays checked_in until Encounter completion.
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    app(StartEncounter::class)->handle(
        encounter: $encounter,
        optometrist: $this->optometrist,
        actor: $this->optometrist,
    );

    $appointment->refresh();
    expect($appointment->status->name)->toBe('fulfilled')
        ->and($appointment->fulfilled_at)->not->toBeNull();
});

test('complete encounter transitions to completed', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter,
        optometrist: $this->optometrist,
        actor: $this->optometrist,
    );
    $encounter = app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
    );

    expect($encounter->status)->toBe(EncounterStatus::Completed)
        ->and($encounter->completed_at)->not->toBeNull();
});

test('only planned encounters can be started', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    app(StartEncounter::class)->handle(
        encounter: $encounter,
        optometrist: $this->optometrist,
        actor: $this->optometrist,
    );

    // Try to start again
    app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        optometrist: $this->optometrist,
        actor: $this->optometrist,
    );
})->throws(ValidationException::class);

test('only in-progress encounters can be completed', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    // Try to complete a planned encounter
    app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
    );
})->throws(ValidationException::class);

// --- Prescription Relationship ---

test('encounter can have prescriptions', function () {
    $encounter = Encounter::factory()->create();

    $prescription = Prescription::factory()->create([
        'encounter_id' => $encounter->id,
        'patient_id' => $encounter->patient_id,
    ]);

    expect($encounter->prescriptions)->toHaveCount(1)
        ->and($encounter->prescriptions->first()->id)->toBe($prescription->id);
});

test('encounter can exist without a prescription', function () {
    $encounter = Encounter::factory()->create();

    expect($encounter->prescriptions)->toHaveCount(0);
});

// --- Encrypted Clinical Fields ---

test('encounter findings and remarks are encrypted', function () {
    $encounter = Encounter::factory()->create([
        'findings' => 'Normal intraocular pressure',
        'remarks' => 'Patient reports improved vision',
    ]);

    $raw = DB::table('encounters')->where('id', $encounter->id)->first();

    expect($raw->findings)->not->toBe('Normal intraocular pressure')
        ->and($raw->findings)->not->toBeNull();

    $fresh = Encounter::find($encounter->id);
    expect($fresh->findings)->toBe('Normal intraocular pressure')
        ->and($fresh->remarks)->toBe('Patient reports improved vision');
});

// --- Audit Logging ---

test('check-in creates an audit event', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    app(CheckInAppointment::class)->handle($appointment);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'appointment.checked_in',
    ]);
});

test('start encounter creates an audit event', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    app(StartEncounter::class)->handle(
        encounter: $encounter,
        optometrist: $this->optometrist,
        actor: $this->optometrist,
    );

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'encounter.started',
    ]);
});
