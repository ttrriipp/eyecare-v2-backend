<?php

use App\Actions\Encounters\CheckInAppointment;
use App\Actions\Encounters\CompleteEncounter;
use App\Actions\Encounters\StartEncounter;
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
    $this->optometrist = User::factory()->optometrist()->create();
    $this->staff = User::factory()->staff()->create();
});

function createTestEncounter(?User $optometrist = null): array
{
    $optometrist ??= test()->optometrist;
    $appointment = Appointment::factory()->create();
    app(CheckInAppointment::class)->handle($appointment);
    $encounter = Encounter::query()->where('appointment_id', $appointment->id)->firstOrFail();
    $encounter->update([
        'optometrist_id' => $optometrist->id,
        'chief_complaint' => 'Blurred vision',
        'findings' => 'Normal anterior segment',
        'assessment' => 'Myopia progression',
        'plan' => 'Update prescription',
    ]);

    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $optometrist,
    );

    return [$encounter, $appointment];
}

test('assigned optometrist can complete encounter', function () {
    [$encounter, $appointment] = createTestEncounter();

    $result = app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
    );

    expect($result->status)->toBe(EncounterStatus::Completed)
        ->and($result->completed_at)->not->toBeNull()
        ->and($result->completed_by)->toBe($this->optometrist->id);
});

test('completion fulfills the appointment', function () {
    [$encounter, $appointment] = createTestEncounter();

    app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
    );

    $appointment->refresh();
    expect($appointment->status->name)->toBe('fulfilled')
        ->and($appointment->fulfilled_at)->not->toBeNull();
});

test('non-assigned optometrist cannot complete encounter', function () {
    [$encounter] = createTestEncounter();
    $otherOptometrist = User::factory()->optometrist()->create();

    app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $otherOptometrist,
    );
})->throws(ValidationException::class, 'Only the assigned optometrist can complete this consultation.');

test('staff cannot complete encounter', function () {
    [$encounter] = createTestEncounter();

    app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->staff,
    );
})->throws(ValidationException::class, 'Only an optometrist can complete a consultation.');

test('plain admin cannot complete encounter', function () {
    [$encounter] = createTestEncounter();
    $admin = User::factory()->admin()->create();

    app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $admin,
    );
})->throws(ValidationException::class, 'Only an optometrist can complete a consultation.');

test('inactive optometrist cannot complete encounter', function () {
    [$encounter] = createTestEncounter();
    $this->optometrist->update(['is_active' => false]);

    app(CompleteEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist->fresh(),
    );
})->throws(ValidationException::class, 'Inactive accounts cannot complete consultations.');

test('planned encounter cannot be completed', function () {
    $appointment = Appointment::factory()->create();
    app(CheckInAppointment::class)->handle($appointment);
    $encounter = Encounter::query()->where('appointment_id', $appointment->id)->firstOrFail();
    $encounter->update(['optometrist_id' => $this->optometrist->id]);

    app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
    );
})->throws(ValidationException::class, 'Only in-progress consultations can be completed.');

test('completion requires chief_complaint', function () {
    [$encounter] = createTestEncounter();
    $encounter->update(['chief_complaint' => null]);

    app(CompleteEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );
})->throws(ValidationException::class);

test('completion requires findings', function () {
    [$encounter] = createTestEncounter();
    $encounter->update(['findings' => null]);

    app(CompleteEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );
})->throws(ValidationException::class);

test('completion requires assessment', function () {
    [$encounter] = createTestEncounter();
    $encounter->update(['assessment' => null]);

    app(CompleteEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );
})->throws(ValidationException::class);

test('completion requires plan', function () {
    [$encounter] = createTestEncounter();
    $encounter->update(['plan' => null]);

    app(CompleteEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );
})->throws(ValidationException::class);

test('completion succeeds without prescription', function () {
    [$encounter] = createTestEncounter();

    $result = app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
    );

    expect($result->status)->toBe(EncounterStatus::Completed);
    expect($result->prescriptions)->toHaveCount(0);
});

test('completion finalizes prescription atomically', function () {
    [$encounter] = createTestEncounter();

    $result = app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        prescriptionData: [
            'main_od_sphere' => '-2.00',
            'main_os_sphere' => '-1.50',
            'remarks' => '62.0',
        ],
    );

    expect($result->status)->toBe(EncounterStatus::Completed);
    expect($result->prescriptions)->toHaveCount(1);

    $prescription = $result->prescriptions->first();
    expect($prescription->main_od_sphere)->toBe('-2.00')
        ->and($prescription->main_os_sphere)->toBe('-1.50')
        ->and($prescription->patient_id)->toBe($encounter->patient_id);
});

test('completion with prescription clears the draft', function () {
    [$encounter] = createTestEncounter();
    $encounter->update([
        'prescription_draft' => [
            'main_od_sphere' => '-2.00',
            'main_os_sphere' => '-1.50',
        ],
    ]);

    $result = app(CompleteEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
        prescriptionData: [
            'main_od_sphere' => '-2.00',
            'main_os_sphere' => '-1.50',
            'remarks' => '62.0',
        ],
    );

    expect($result->status)->toBe(EncounterStatus::Completed)
        ->and($result->prescription_draft)->toBeNull()
        ->and($result->prescriptions)->toHaveCount(1);
});

test('completion creates audit event', function () {
    [$encounter] = createTestEncounter();

    app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
    );

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'encounter.completed',
    ]);
});

test('stale encounter cannot be completed twice', function () {
    [$encounter] = createTestEncounter();

    app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
    );

    app(CompleteEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
    );
})->throws(ValidationException::class, 'Only in-progress consultations can be completed.');
