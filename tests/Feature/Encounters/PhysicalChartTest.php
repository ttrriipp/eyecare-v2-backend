<?php

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\PhysicalChartEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('chart event belongs to a patient', function () {
    $patient = Patient::factory()->create();
    $event = PhysicalChartEvent::factory()->create(['patient_id' => $patient->id]);

    expect($event->patient->id)->toBe($patient->id);
});

test('chart event can be linked to an encounter', function () {
    $encounter = Encounter::factory()->create();
    $event = PhysicalChartEvent::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
    ]);

    expect($event->encounter_id)->toBe($encounter->id)
        ->and($event->encounter->id)->toBe($encounter->id);
});

test('chart event records the actor', function () {
    $staff = User::factory()->staff()->create();
    $event = PhysicalChartEvent::factory()->create(['actor_id' => $staff->id]);

    expect($event->actor->id)->toBe($staff->id);
});

test('chart event types include checkout return relocation copy', function () {
    $checkout = PhysicalChartEvent::factory()->checkout()->create();
    $return = PhysicalChartEvent::factory()->returned()->create();

    expect($checkout->event_type)->toBe('checkout')
        ->and($return->event_type)->toBe('return');
});

test('chart event notes are optional', function () {
    $withNotes = PhysicalChartEvent::factory()->create(['notes' => 'Checked out for review']);
    $withoutNotes = PhysicalChartEvent::factory()->create(['notes' => null]);

    expect($withNotes->notes)->toBe('Checked out for review')
        ->and($withoutNotes->notes)->toBeNull();
});

test('multiple events can be recorded for the same patient', function () {
    $patient = Patient::factory()->create();

    PhysicalChartEvent::factory()->checkout()->create(['patient_id' => $patient->id]);
    PhysicalChartEvent::factory()->returned()->create(['patient_id' => $patient->id]);

    expect(PhysicalChartEvent::where('patient_id', $patient->id)->count())->toBe(2);
});
