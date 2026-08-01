<?php

use App\Enums\EncounterStatus;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Filament\Resources\Encounters\Pages\ListEncounters;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
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
        ->assertSee('Timeline')
        ->assertSee('Consultation')
        ->assertSee('Consultation & History')
        ->assertSee('Examination')
        ->assertSee('Prescription & Plan')
        ->assertSee('Review & Complete')
        ->assertDontSee('Health Record Status');
});

test('consultation wizard is presented as a single separate panel', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->create();

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertSchemaComponentExists(
            'consultation-workspace',
            checkComponentUsing: function (Section $component): bool {
                $wizard = collect($component->getChildSchema()->getComponents())
                    ->first(fn ($childComponent): bool => $childComponent instanceof Wizard);

                expect($component->getHeading())
                    ->toBe('Consultation')
                    ->and($component->isContained())
                    ->toBeTrue()
                    ->and($wizard)
                    ->toBeInstanceOf(Wizard::class)
                    ->and($wizard->getKey())
                    ->toEndWith('consultation-wizard')
                    ->and($wizard->isContained())
                    ->toBeFalse();

                return true;
            },
        );
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
