<?php

use App\Actions\Encounters\AssignEncounterOptometrist;
use App\Actions\Encounters\CheckInAppointment;
use App\Enums\EncounterStatus;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->optometrist = User::factory()->optometrist()->create();
    $this->otherOptometrist = User::factory()->optometrist()->create();
});

function createPlannedEncounter(): array
{
    $appointment = Appointment::factory()->create();
    app(CheckInAppointment::class)->handle($appointment);
    $encounter = Encounter::query()->where('appointment_id', $appointment->id)->firstOrFail();

    return [$encounter, $appointment];
}

test('staff can assign optometrist to planned encounter', function () {
    [$encounter, $appointment] = createPlannedEncounter();

    $result = app(AssignEncounterOptometrist::class)->handle(
        encounter: $encounter,
        actor: $this->staff,
        optometrist: $this->optometrist,
    );

    expect($result->optometrist_id)->toBe($this->optometrist->id);
    $appointment->refresh();
    expect($appointment->optometrist_id)->toBe($this->optometrist->id);
});

test('optometrist can assign optometrist to planned encounter', function () {
    [$encounter] = createPlannedEncounter();

    $result = app(AssignEncounterOptometrist::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        optometrist: $this->otherOptometrist,
    );

    expect($result->optometrist_id)->toBe($this->otherOptometrist->id);
});

test('admin can assign optometrist to planned encounter', function () {
    [$encounter] = createPlannedEncounter();
    $admin = User::factory()->admin()->create();

    $result = app(AssignEncounterOptometrist::class)->handle(
        encounter: $encounter,
        actor: $admin,
        optometrist: $this->optometrist,
    );

    expect($result->optometrist_id)->toBe($this->optometrist->id);
});

test('patient cannot assign optometrist', function () {
    [$encounter] = createPlannedEncounter();
    $patient = User::factory()->patient()->create();

    app(AssignEncounterOptometrist::class)->handle(
        encounter: $encounter,
        actor: $patient,
        optometrist: $this->optometrist,
    );
})->throws(ValidationException::class);

test('cannot assign to in-progress encounter', function () {
    [$encounter] = createPlannedEncounter();
    $encounter->update([
        'optometrist_id' => $this->optometrist->id,
        'status' => EncounterStatus::InProgress,
        'started_at' => now(),
    ]);

    app(AssignEncounterOptometrist::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->staff,
        optometrist: $this->otherOptometrist,
    );
})->throws(ValidationException::class);

test('cannot assign inactive optometrist', function () {
    [$encounter] = createPlannedEncounter();
    $inactiveOptometrist = User::factory()->optometrist()->create(['is_active' => false]);

    app(AssignEncounterOptometrist::class)->handle(
        encounter: $encounter,
        actor: $this->staff,
        optometrist: $inactiveOptometrist,
    );
})->throws(ValidationException::class);

test('cannot assign non-optometrist user', function () {
    [$encounter] = createPlannedEncounter();
    $staff = User::factory()->staff()->create();

    app(AssignEncounterOptometrist::class)->handle(
        encounter: $encounter,
        actor: $this->staff,
        optometrist: $staff,
    );
})->throws(ValidationException::class);

test('assignment synchronizes encounter and appointment providers', function () {
    [$encounter, $appointment] = createPlannedEncounter();

    app(AssignEncounterOptometrist::class)->handle(
        encounter: $encounter,
        actor: $this->staff,
        optometrist: $this->optometrist,
    );

    $encounter->refresh();
    $appointment->refresh();

    expect($encounter->optometrist_id)->toBe($this->optometrist->id)
        ->and($appointment->optometrist_id)->toBe($this->optometrist->id);
});

test('assignment creates audit event', function () {
    [$encounter] = createPlannedEncounter();

    app(AssignEncounterOptometrist::class)->handle(
        encounter: $encounter,
        actor: $this->staff,
        optometrist: $this->optometrist,
    );

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'encounter.provider_assigned',
    ]);
});
