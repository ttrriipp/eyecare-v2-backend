<?php

use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can add a service charge to a completed encounter', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionVisible('addCharge')
        ->callAction('addCharge', [
            'items' => [[
                'description' => 'Eye Exam',
                'quantity' => 1,
                'unit_price' => 1500,
            ]],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $billing = BillingRecord::query()->where('encounter_id', $encounter->id)->firstOrFail();

    expect((float) $billing->subtotal_amount)->toBe(1500.0)
        ->and($billing->items)->toHaveCount(1);
});

test('add charge action is hidden for encounters that are not completed', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->inProgress()->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionHidden('addCharge');
});

test('add charge action requires at least one item', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->callAction('addCharge', ['items' => []])
        ->assertHasActionErrors(['items' => 'min']);

    expect(BillingRecord::query()->where('encounter_id', $encounter->id)->exists())->toBeFalse();
});
