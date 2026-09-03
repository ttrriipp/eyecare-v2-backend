<?php

use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\Service;
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
        ->mountAction('addCharge')
        ->assertMountedActionModalSee([
            'Add Service Charge',
            'Service source',
            'Catalog service',
            'Custom service',
            'Add Service Line',
            'Total',
            'Add to Billing',
        ])
        ->unmountAction()
        ->callAction('addCharge', [
            'items' => [[
                'service_source' => 'custom',
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

test('edit consultation does not expose a view billing record action', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionDoesNotExist('viewBillingRecord')
        ->callAction('addCharge', [
            'items' => [[
                'service_source' => 'custom',
                'description' => 'Eye Exam',
                'quantity' => 1,
                'unit_price' => 1500,
            ]],
        ]);

    expect(BillingRecord::query()->where('encounter_id', $encounter->id)->exists())->toBeTrue();

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionDoesNotExist('viewBillingRecord');
});

test('catalog service charges from a completed encounter use the selected service price', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();
    $service = Service::factory()->create([
        'name' => 'Comprehensive Eye Exam',
        'price' => 800,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->callAction('addCharge', [
            'items' => [[
                'service_source' => 'catalog',
                'service_id' => $service->id,
                'description' => 'Tampered description',
                'quantity' => 1,
                'unit_price' => 1,
            ]],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $billingItem = BillingRecord::query()
        ->where('encounter_id', $encounter->id)
        ->firstOrFail()
        ->items
        ->firstOrFail();

    expect($billingItem->service_id)->toBe($service->id)
        ->and($billingItem->encounter_id)->toBe($encounter->id)
        ->and($billingItem->description)->toBe('Comprehensive Eye Exam')
        ->and((float) $billingItem->unit_price)->toBe(800.0)
        ->and((float) $billingItem->amount)->toBe(800.0);
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
