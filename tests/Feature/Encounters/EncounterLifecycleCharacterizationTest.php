<?php

use App\Actions\Encounters\CheckInAppointment;
use App\Actions\Encounters\CompleteEncounter;
use App\Actions\Encounters\StartEncounter;
use App\Enums\EncounterStatus;
use App\Enums\IntakeStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\PatientIntake;
use App\Models\Prescription;
use App\Models\Role;
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

// --- Intake No Longer Attached ---

test('check-in does not attach patient intake', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    PatientIntake::factory()->verified()->create([
        'patient_id' => $appointment->patient_id,
        'appointment_id' => $appointment->id,
        'status' => IntakeStatus::Verified,
    ]);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->patient_intake_id)->toBeNull();
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
    $encounter->update(['optometrist_id' => $this->optometrist->id]);
    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );

    expect($encounter->status)->toBe(EncounterStatus::InProgress)
        ->and($encounter->started_at)->not->toBeNull()
        ->and($encounter->optometrist_id)->toBe($this->optometrist->id);
});

test('start encounter leaves appointment checked_in', function () {
    // Starting consultation leaves the appointment checked_in.
    // The appointment is only fulfilled when the encounter is completed.
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    $encounter->update(['optometrist_id' => $this->optometrist->id]);
    app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );

    $appointment->refresh();
    expect($appointment->status->name)->toBe('checked_in');
});

test('complete encounter transitions to completed', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    $encounter->update([
        'optometrist_id' => $this->optometrist->id,
        'chief_complaint' => 'Blurred vision',
        'findings' => 'Normal anterior segment',
        'assessment' => 'Myopia progression',
        'plan' => 'Update prescription',
    ]);
    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
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
    $encounter->update(['optometrist_id' => $this->optometrist->id]);
    app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );

    // Try to start again
    app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
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
    $encounter->update(['optometrist_id' => $this->optometrist->id]);
    app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'encounter.started',
    ]);
});

// --- LEGACY BEHAVIORS: These tests document current behavior that later tasks intentionally replace ---

test('legacy: start encounter no longer accepts a separate optometrist parameter (changed to self-claim only)', function () {
    // This behavior changed in Task 5: StartEncounter now only accepts actor.
    // The actor becomes the provider (self-claim) or must be the assigned provider.
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    $encounter->update(['optometrist_id' => $this->optometrist->id]);

    // The assigned optometrist starts (self-claim pattern)
    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );

    expect($encounter->optometrist_id)->toBe($this->optometrist->id);
});

test('legacy: admin optometrist can no longer complete another providers encounter (restricted to assigned only)', function () {
    // This behavior changed in Task 8: only the assigned optometrist can complete.
    $adminOptometrist = User::factory()->optometrist()->create();
    $adminOptometrist->roles()->attach(
        Role::query()->where('name', 'admin')->firstOrFail()
    );

    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    $encounter->update([
        'optometrist_id' => $this->optometrist->id,
        'chief_complaint' => 'Blurred vision',
        'findings' => 'Normal anterior segment',
        'assessment' => 'Myopia progression',
        'plan' => 'Update prescription',
    ]);
    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );

    // Admin optometrist (not the assigned one) cannot complete
    app(CompleteEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $adminOptometrist,
    );
})->throws(ValidationException::class);

test('check-in no longer attaches patient intake (previously attached verified intake)', function () {
    // This behavior changed in Task 4: check-in no longer attaches PatientIntake.
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    PatientIntake::factory()->verified()->create([
        'patient_id' => $appointment->patient_id,
        'appointment_id' => $appointment->id,
        'status' => IntakeStatus::Verified,
    ]);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->patient_intake_id)->toBeNull();
});

test('completion fulfills the appointment and records attribution', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    $encounter->update([
        'optometrist_id' => $this->optometrist->id,
        'chief_complaint' => 'Blurred vision',
        'findings' => 'Normal anterior segment',
        'assessment' => 'Myopia progression',
        'plan' => 'Update prescription',
    ]);
    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );
    $encounter = app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
    );

    $appointment->refresh();
    expect($appointment->status->name)->toBe('fulfilled')
        ->and($appointment->fulfilled_at)->not->toBeNull()
        ->and($encounter->completed_by)->toBe($this->optometrist->id)
        ->and($encounter->completed_at)->not->toBeNull();
});

test('completion creates an audit event with identifiers only', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    $encounter->update([
        'optometrist_id' => $this->optometrist->id,
        'chief_complaint' => 'Blurred vision',
        'findings' => 'Normal anterior segment',
        'assessment' => 'Myopia progression',
        'plan' => 'Update prescription',
    ]);
    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );
    app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
    );

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'encounter.completed',
    ]);

    $auditLog = AuditLog::query()
        ->where('action', 'encounter.completed')
        ->first();

    expect($auditLog->metadata)->toHaveKey('appointment_id')
        ->and($auditLog->metadata)->toHaveKey('optometrist_id')
        ->and($auditLog->metadata)->toHaveKey('actor_id');
});
