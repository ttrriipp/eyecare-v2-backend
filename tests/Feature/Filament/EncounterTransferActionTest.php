<?php

use App\Actions\Encounters\CheckInAppointment;
use App\Actions\Encounters\StartEncounter;
use App\Enums\EncounterTransferReason;
use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

function createInProgressForFilamentTransfer(?User $optometrist = null): Encounter
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

    return app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $optometrist,
    );
}

test('current provider can see transfer action', function () {
    $encounter = createInProgressForFilamentTransfer();

    $this->actingAs($this->optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionExists('transferEncounter')
        ->assertActionVisible('transferEncounter');
});

test('admin can see transfer action', function () {
    $encounter = createInProgressForFilamentTransfer();

    $this->actingAs($this->admin);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionExists('transferEncounter')
        ->assertActionVisible('transferEncounter');
});

test('staff cannot see transfer action', function () {
    $encounter = createInProgressForFilamentTransfer();

    $this->actingAs($this->staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionHidden('transferEncounter');
});

test('non-assigned optometrist cannot see transfer action', function () {
    $encounter = createInProgressForFilamentTransfer();
    $thirdOptometrist = User::factory()->optometrist()->create();

    $this->actingAs($thirdOptometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionHidden('transferEncounter');
});

test('transfer action shows optometrist choices and reason enum', function () {
    $encounter = createInProgressForFilamentTransfer();

    $this->actingAs($this->optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->mountAction('transferEncounter')
        ->assertFormFieldExists('new_optometrist_id')
        ->assertFormFieldExists('reason');
});

test('transfer action delegates to TransferEncounter', function () {
    $encounter = createInProgressForFilamentTransfer();

    $this->actingAs($this->optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->mountAction('transferEncounter')
        ->setActionData([
            'new_optometrist_id' => $this->otherOptometrist->id,
            'reason' => EncounterTransferReason::ProviderUnavailable->value,
        ])
        ->callMountedAction();

    $encounter->refresh();
    expect($encounter->optometrist_id)->toBe($this->otherOptometrist->id);
});
