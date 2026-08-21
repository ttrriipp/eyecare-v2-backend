<?php

use App\Actions\Encounters\CheckInAppointment;
use App\Actions\Encounters\StartEncounter;
use App\Actions\Encounters\TransferEncounter;
use App\Enums\EncounterTransferReason;
use App\Models\Appointment;
use App\Models\AuditLog;
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
    $this->optometrist = User::factory()->optometrist()->create();
    $this->otherOptometrist = User::factory()->optometrist()->create();
    $this->admin = User::factory()->admin()->create();
    $this->staff = User::factory()->staff()->create();
});

function createInProgressEncounterForTransfer(?User $optometrist = null): array
{
    $optometrist ??= test()->optometrist;
    $appointment = Appointment::factory()->create();
    app(CheckInAppointment::class)->handle($appointment);
    $encounter = Encounter::query()->where('appointment_id', $appointment->id)->firstOrFail();
    $encounter->update([
        'optometrist_id' => $optometrist->id,
        'chief_complaint' => 'Blurred vision',
        'findings' => 'Normal',
        'assessment' => 'Myopia',
        'plan' => 'Update prescription',
    ]);

    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $optometrist,
    );

    return [$encounter, $appointment];
}

test('current provider can transfer encounter', function () {
    [$encounter, $appointment] = createInProgressEncounterForTransfer();

    $result = app(TransferEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        newOptometrist: $this->otherOptometrist,
        reason: EncounterTransferReason::ProviderUnavailable,
    );

    expect($result->optometrist_id)->toBe($this->otherOptometrist->id);
    $appointment->refresh();
    expect($appointment->optometrist_id)->toBe($this->otherOptometrist->id);
});

test('admin can transfer encounter', function () {
    [$encounter] = createInProgressEncounterForTransfer();

    $result = app(TransferEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->admin,
        newOptometrist: $this->otherOptometrist,
        reason: EncounterTransferReason::ShiftChange,
    );

    expect($result->optometrist_id)->toBe($this->otherOptometrist->id);
});

test('staff cannot transfer encounter', function () {
    [$encounter] = createInProgressEncounterForTransfer();

    app(TransferEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->staff,
        newOptometrist: $this->otherOptometrist,
        reason: EncounterTransferReason::ProviderUnavailable,
    );
})->throws(ValidationException::class, 'Only the current treating optometrist or an administrator can transfer this consultation.');

test('non-assigned optometrist cannot transfer encounter', function () {
    [$encounter] = createInProgressEncounterForTransfer();
    $thirdOptometrist = User::factory()->optometrist()->create();

    app(TransferEncounter::class)->handle(
        encounter: $encounter,
        actor: $thirdOptometrist,
        newOptometrist: $this->otherOptometrist,
        reason: EncounterTransferReason::ProviderUnavailable,
    );
})->throws(ValidationException::class, 'Only the current treating optometrist or an administrator can transfer this consultation.');

test('cannot transfer to same optometrist', function () {
    [$encounter] = createInProgressEncounterForTransfer();

    app(TransferEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        newOptometrist: $this->optometrist,
        reason: EncounterTransferReason::ProviderUnavailable,
    );
})->throws(ValidationException::class, 'The consultation is already assigned to this optometrist.');

test('cannot transfer to inactive optometrist', function () {
    [$encounter] = createInProgressEncounterForTransfer();
    $inactiveOptometrist = User::factory()->optometrist()->create(['is_active' => false]);

    app(TransferEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        newOptometrist: $inactiveOptometrist,
        reason: EncounterTransferReason::ProviderUnavailable,
    );
})->throws(ValidationException::class);

test('cannot transfer non-optometrist user', function () {
    [$encounter] = createInProgressEncounterForTransfer();

    app(TransferEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        newOptometrist: $this->staff,
        reason: EncounterTransferReason::ProviderUnavailable,
    );
})->throws(ValidationException::class);

test('cannot transfer planned encounter', function () {
    $appointment = Appointment::factory()->create();
    app(CheckInAppointment::class)->handle($appointment);
    $encounter = Encounter::query()->where('appointment_id', $appointment->id)->firstOrFail();
    $encounter->update(['optometrist_id' => $this->optometrist->id]);

    app(TransferEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        newOptometrist: $this->otherOptometrist,
        reason: EncounterTransferReason::ProviderUnavailable,
    );
})->throws(ValidationException::class, 'Only in-progress consultations can be transferred.');

test('transfer preserves draft data', function () {
    [$encounter] = createInProgressEncounterForTransfer();
    $encounter->update([
        'chief_complaint' => 'Original complaint',
        'prescription_draft' => ['main_od_sphere' => '-2.00'],
    ]);

    $result = app(TransferEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
        newOptometrist: $this->otherOptometrist,
        reason: EncounterTransferReason::ProviderUnavailable,
    );

    expect($result->chief_complaint)->toBe('Original complaint')
        ->and($result->prescription_draft)->toBe(['main_od_sphere' => '-2.00']);
});

test('transfer synchronizes encounter and appointment providers', function () {
    [$encounter, $appointment] = createInProgressEncounterForTransfer();

    app(TransferEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        newOptometrist: $this->otherOptometrist,
        reason: EncounterTransferReason::PatientRequest,
    );

    $encounter->refresh();
    $appointment->refresh();

    expect($encounter->optometrist_id)->toBe($this->otherOptometrist->id)
        ->and($appointment->optometrist_id)->toBe($this->otherOptometrist->id);
});

test('transfer creates audit event with identifiers and reason', function () {
    [$encounter] = createInProgressEncounterForTransfer();

    app(TransferEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        newOptometrist: $this->otherOptometrist,
        reason: EncounterTransferReason::Emergency,
    );

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'encounter.transferred',
    ]);

    $auditLog = AuditLog::query()
        ->where('action', 'encounter.transferred')
        ->first();

    expect($auditLog->metadata)->toHaveKey('appointment_id')
        ->and($auditLog->metadata)->toHaveKey('previous_optometrist_id')
        ->and($auditLog->metadata)->toHaveKey('new_optometrist_id')
        ->and($auditLog->metadata)->toHaveKey('reason')
        ->and($auditLog->metadata)->toHaveKey('actor_id')
        ->and($auditLog->metadata['reason'])->toBe('emergency');
});

test('only new provider can edit after transfer', function () {
    [$encounter] = createInProgressEncounterForTransfer();

    $result = app(TransferEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        newOptometrist: $this->otherOptometrist,
        reason: EncounterTransferReason::ProviderUnavailable,
    );

    // The new optometrist should be the assigned provider
    expect($result->optometrist_id)->toBe($this->otherOptometrist->id);

    // The old optometrist is no longer the assigned provider
    expect($result->optometrist_id)->not->toBe($this->optometrist->id);
});
