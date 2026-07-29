<?php

use App\Enums\EncounterStatus;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Filament\Resources\Encounters\Pages\ListEncounters;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('optometrist can list encounters', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounters = Encounter::factory()->count(3)->create();

    $this->actingAs($optometrist);

    Livewire::test(ListEncounters::class)
        ->assertCanSeeTableRecords($encounters);
});

test('optometrist can view encounter details', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'status' => EncounterStatus::Planned,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Encounter Information')
        ->assertSee('Patient Information')
        ->assertSee('Clinical Context')
        ->assertSee('Visit Logistics')
        ->assertSee('Health Record Status')
        ->assertSee('Not Started')
        ->assertDontSee('Visit Details')
        ->assertDontSee('Waiting to be seen');
});

test('encounter table shows status badges', function () {
    $optometrist = User::factory()->optometrist()->create();
    $waiting = Encounter::factory()->create(['status' => EncounterStatus::Planned]);
    $inProgress = Encounter::factory()->inProgress()->create();
    $completed = Encounter::factory()->completed()->create();

    $this->actingAs($optometrist);

    Livewire::test(ListEncounters::class)
        ->assertTableColumnFormattedStateSet('status', 'Planned', record: $waiting)
        ->assertTableColumnFormattedStateSet('status', 'In Progress', record: $inProgress)
        ->assertTableColumnFormattedStateSet('status', 'Completed', record: $completed);
});

test('encounter table can filter by status', function () {
    $optometrist = User::factory()->optometrist()->create();
    $waiting = Encounter::factory()->create(['status' => EncounterStatus::Planned]);
    $completed = Encounter::factory()->completed()->create();

    $this->actingAs($optometrist);

    Livewire::test(ListEncounters::class)
        ->filterTable('status', EncounterStatus::Planned->value)
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$completed]);
});

test('receptionist can see encounters but not clinical authoring', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $encounter = Encounter::factory()->create();

    $this->actingAs($staff);

    // Staff can list and view encounters
    Livewire::test(ListEncounters::class)
        ->assertCanSeeTableRecords([$encounter]);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertSuccessful();
});

test('encounter resource is registered', function () {
    expect(EncounterResource::getModel())->toBe(Encounter::class);
});
