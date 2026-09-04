<?php

use App\Enums\BillingRecordStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\OpticalOrders\Pages\CreateDirectOpticalOrder;
use App\Filament\Resources\OpticalOrders\Pages\ListOpticalOrders;
use App\Models\JobOrder;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Product;
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
        ->assertActionHasUrl('newDirectOrder', OpticalOrderResource::getUrl('create'));

    Livewire::test(CreateDirectOpticalOrder::class)
        ->fillForm([
            'patient_id' => $patient->id,
            'fulfillment_mode' => 'prepared',
            'items' => [[
                'item_kind' => 'catalog',
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Order created')
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

    Livewire::test(CreateDirectOpticalOrder::class)
        ->fillForm([
            'fulfillment_mode' => 'immediate',
        ])
        ->assertFormFieldDoesNotExist('recipient_name')
        ->fillForm([
            'patient_id' => $patient->id,
            'fulfillment_mode' => 'immediate',
            'items' => [[
                'item_kind' => 'catalog',
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ]],
            'deposit_amount' => 1200,
            'deposit_payment_method' => 'cash',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Order created');

    $jobOrder = JobOrder::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($jobOrder->status->value)->toBe('dispensed')
        ->and($jobOrder->billingRecord->status)->toBe(BillingRecordStatus::Paid)
        ->and((float) $jobOrder->billingRecord->balance_due)->toBe(0.0)
        ->and($jobOrder->dispensingEvents()->latest()->value('recipient_name'))->toBe($patient->full_name);
});

test('new direct order action requires at least one item', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    Livewire::test(CreateDirectOpticalOrder::class)
        ->fillForm([
            'patient_id' => $patient->id,
            'items' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['items' => 'min']);

    expect(JobOrder::query()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

test('direct order page reveals the dedicated eyewear builder for a selected prescription', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($staff);

    Livewire::test(CreateDirectOpticalOrder::class, [
        'patient' => (string) $patient->id,
        'prescription' => (string) $prescription->id,
    ])
        ->assertSuccessful()
        ->assertSee([
            'New Direct Optical Order',
            'Include prescription eyewear',
            'Prescription Eyewear',
            'Other Items',
            'Fulfillment',
            'Payment',
            'Create Order & Billing',
        ])
        ->assertFormFieldExists('eyewear_frame_source')
        ->assertFormFieldExists('eyewear_lens_category_id')
        ->assertFormFieldExists('eyewear_lens_options');
});

test('staff creates a direct prescription eyewear order with other items and payment', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);
    $frame = Product::factory()->create(['product_type' => 'frame', 'name' => 'Aster Frame']);
    $frameVariant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'name' => 'Matte Black',
        'sku' => 'FRM-AST-BLK',
        'price' => 2450,
        'stock_quantity' => 10,
    ]);
    $accessory = Product::factory()->accessory()->create(['name' => 'Cleaning Kit']);
    $accessoryVariant = ProductVariant::factory()->create([
        'product_id' => $accessory->id,
        'price' => 250,
        'stock_quantity' => 10,
    ]);
    $lensCategory = LensCategory::factory()->withPrice(1800)->create();
    $lensOption = LensOption::factory()->create(['price' => 600]);

    $this->actingAs($staff);

    Livewire::test(CreateDirectOpticalOrder::class, [
        'patient' => (string) $patient->id,
        'prescription' => (string) $prescription->id,
    ])
        ->fillForm([
            'eyewear_frame_source' => 'catalog',
            'eyewear_frame_variant_id' => $frameVariant->id,
            'eyewear_lens_category_id' => $lensCategory->id,
            'eyewear_lens_options' => [['lens_option_id' => $lensOption->id]],
            'items' => [[
                'item_kind' => 'catalog',
                'product_variant_id' => $accessoryVariant->id,
                'quantity' => 1,
            ]],
            'fulfillment_mode' => 'prepared',
            'uses_external_supplier' => true,
            'deposit_amount' => 5100,
            'deposit_payment_method' => 'cash',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Order created')
        ->assertRedirect();

    $order = JobOrder::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($order->prescription_id)->toBe($prescription->id)
        ->and($order->fulfillment_mode)->toBe('prepared')
        ->and($order->uses_external_supplier)->toBeTrue()
        ->and($order->items)->toHaveCount(4)
        ->and($order->billingRecord)->not->toBeNull()
        ->and((float) $order->billingRecord->balance_due)->toBe(0.0);
});
