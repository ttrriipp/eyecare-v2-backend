<?php

use App\Enums\BillingRecordStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\OpticalOrders\Pages\ListOpticalOrders;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff creates a direct order from the optical orders list', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 1500]);

    $this->actingAs($staff);

    Livewire::test(ListOpticalOrders::class)
        ->assertActionVisible('newDirectOrder')
        ->callAction('newDirectOrder', [
            'patient_id' => $patient->id,
            'fulfillment_mode' => 'prepared',
            'items' => [[
                'item_kind' => 'catalog',
                'product_variant_id' => $variant->id,
                'description' => 'Frame',
                'quantity' => 1,
                'unit_price' => 1500,
            ]],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified()
        ->assertRedirect();

    $jobOrder = JobOrder::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($jobOrder->quotation_id)->toBeNull()
        ->and((float) $jobOrder->total_amount)->toBe(1500.0);

    expect(OpticalOrderResource::getUrl('edit', ['record' => $jobOrder]))
        ->toContain("/optical-orders/{$jobOrder->id}/edit");
});

test('immediate checkout paid in full is dispensed with a zero balance', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 1200]);

    $this->actingAs($staff);

    Livewire::test(ListOpticalOrders::class)
        ->callAction('newDirectOrder', [
            'patient_id' => $patient->id,
            'fulfillment_mode' => 'immediate',
            'items' => [[
                'item_kind' => 'catalog',
                'product_variant_id' => $variant->id,
                'description' => 'Reading Glasses',
                'quantity' => 1,
                'unit_price' => 1200,
            ]],
            'deposit_amount' => 1200,
            'deposit_payment_method' => 'cash',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $jobOrder = JobOrder::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($jobOrder->status->value)->toBe('dispensed')
        ->and($jobOrder->billingRecord->status)->toBe(BillingRecordStatus::Paid)
        ->and((float) $jobOrder->billingRecord->balance_due)->toBe(0.0);
});

test('new direct order action requires at least one item', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ListOpticalOrders::class)
        ->callAction('newDirectOrder', [
            'patient_id' => $patient->id,
            'items' => [],
        ])
        ->assertHasActionErrors(['items' => 'min']);

    expect(JobOrder::query()->where('patient_id', $patient->id)->exists())->toBeFalse();
});
