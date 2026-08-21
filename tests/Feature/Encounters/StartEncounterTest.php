<?php

use App\Actions\Encounters\CheckInAppointment;
use App\Actions\Encounters\StartEncounter;
use App\Enums\EncounterStatus;
use App\Models\Appointment;
use App\Models\Role;
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
    $this->adminOptometrist = User::factory()->optometrist()->create();
    $this->adminOptometrist->roles()->attach(
        Role::query()->where('name', 'admin')->firstOrFail()
    );
});

test('assigned optometrist can start encounter', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    $encounter->update(['optometrist_id' => $this->optometrist->id]);

    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );

    expect($encounter->status)->toBe(EncounterStatus::InProgress)
        ->and($encounter->optometrist_id)->toBe($this->optometrist->id)
        ->and($encounter->started_at)->not->toBeNull();
});

test('unassigned encounter can be self-claimed by starting optometrist', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    expect($encounter->optometrist_id)->toBeNull();

    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );

    expect($encounter->status)->toBe(EncounterStatus::InProgress)
        ->and($encounter->optometrist_id)->toBe($this->optometrist->id);
});

test('self-claim synchronizes appointment provider', function () {
    $appointment = Appointment::factory()->create([
        'optometrist_id' => null,
    ]);
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );

    $appointment->refresh();
    expect($appointment->optometrist_id)->toBe($this->optometrist->id);
});

test('cannot start encounter for another provider', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    $encounter->update(['optometrist_id' => $this->optometrist->id]);

    // Other optometrist tries to start
    app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->otherOptometrist,
    );
})->throws(ValidationException::class, 'Only the assigned optometrist can start this consultation.');

test('non-optometrist cannot start encounter', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    app(StartEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->staff,
    );
})->throws(ValidationException::class, 'Only an optometrist can start a consultation.');

test('plain admin cannot start encounter', function () {
    $admin = User::factory()->admin()->create();
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    app(StartEncounter::class)->handle(
        encounter: $encounter,
        actor: $admin,
    );
})->throws(ValidationException::class);

test('admin optometrist can start their own encounter', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    $encounter->update(['optometrist_id' => $this->adminOptometrist->id]);

    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->adminOptometrist,
    );

    expect($encounter->status)->toBe(EncounterStatus::InProgress)
        ->and($encounter->optometrist_id)->toBe($this->adminOptometrist->id);
});

test('cannot start already started encounter', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    $encounter->update(['optometrist_id' => $this->optometrist->id]);

    app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );

    app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );
})->throws(ValidationException::class, 'Only planned consultations can be started.');

test('inactive optometrist cannot start encounter', function () {
    $inactiveOptometrist = User::factory()->optometrist()->create(['is_active' => false]);
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);
    $encounter->update(['optometrist_id' => $inactiveOptometrist->id]);

    app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $inactiveOptometrist,
    );
})->throws(ValidationException::class, 'Inactive accounts cannot start consultations.');

test('start encounter creates audit event', function () {
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
