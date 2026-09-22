<?php

use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('completed consultation uses the bill action instead of a service charge action', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionVisible('createBill')
        ->assertActionDoesNotExist('addCharge');
});

test('completed consultation keeps the bill action when an optical order has no consultation bill', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();

    JobOrder::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionVisible('createBill');
});

test('completed consultation hides the bill action when an active consultation bill exists', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();

    BillingRecord::factory()->encounterOnly()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionHidden('createBill');
});
