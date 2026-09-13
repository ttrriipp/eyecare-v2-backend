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
use Database\Seeders\NotificationStatusSeeder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('direct order discount input uses spinner-free decimal styling', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateDirectOpticalOrder::class)
        ->assertSchemaComponentExists(
            'discount_amount',
            checkComponentUsing: function (TextInput $field): bool {
                expect($field->getMinValue())
                    ->toBe(0)
                    ->and($field->getStep())
                    ->toBe(0.01)
                    ->and($field->getExtraInputAttributes())
                    ->toMatchArray(['class' => 'price-input']);

                return true;
            },
        );
});

test('custom items explain which products can be entered manually', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateDirectOpticalOrder::class)
        ->fillForm([
            'items' => [[
                'item_kind' => 'custom',
            ]],
        ])
        ->assertSee('product not listed in the catalog')
        ->assertSee('special-order frame')
        ->assertSee('replacement nose pads');
});

test('selecting a patient preserves a selected catalog frame and its price', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'name' => 'Aster Frame',
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'name' => 'Matte Black',
        'price' => 2450,
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(CreateDirectOpticalOrder::class);
    $itemKey = array_key_first($component->get('data.items'));

    $component
        ->set("data.items.{$itemKey}.product_variant_id", $variant->id)
        ->assertFormSet([
            "items.{$itemKey}.description" => 'Aster Frame — Matte Black',
            "items.{$itemKey}.unit_price" => '2450.00',
            "items.{$itemKey}.line_total" => '2,450.00',
        ]);

    $quantityField = collect($component->instance()->form->getFlatFields(withHidden: true))
        ->first(fn (Field $field, string $key): bool => str_ends_with($key, '.quantity'));

    expect($quantityField?->isDisabled())->toBeFalse();

    $component
        ->set("data.items.{$itemKey}.quantity", 2)
        ->assertFormSet([
            "items.{$itemKey}.quantity" => 2,
            "items.{$itemKey}.line_total" => '4,900.00',
        ])
        ->set('data.patient_id', $patient->id)
        ->assertFormSet([
            "items.{$itemKey}.product_variant_id" => $variant->id,
            "items.{$itemKey}.description" => 'Aster Frame — Matte Black',
            "items.{$itemKey}.unit_price" => '2450.00',
            "items.{$itemKey}.line_total" => '4,900.00',
            "items.{$itemKey}.quantity" => 2,
        ]);

    $quantityField = collect($component->instance()->form->getFlatFields(withHidden: true))
        ->first(fn (Field $field, string $key): bool => str_ends_with($key, '.quantity'));

    expect($quantityField?->isDisabled())->toBeFalse();
});

test('staff creates a direct order from the optical orders list', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 1500]);

    $this->seed(NotificationStatusSeeder::class);
    $this->actingAs($staff);

    Livewire::test(ListOpticalOrders::class)
        ->assertActionVisible('newDirectOrder')
        ->assertActionHasIcon('newDirectOrder', 'heroicon-o-plus-circle')
        ->assertActionHasUrl('newDirectOrder', OpticalOrderResource::getUrl('create'))
        ->assertTableActionDoesNotExist('newDirectOrder');

    Livewire::test(CreateDirectOpticalOrder::class)
        ->fillForm([
            'patient_id' => $patient->id,
            'fulfillment_mode' => 'prepared',
            'items' => [[
                'item_kind' => 'catalog',
                'product_variant_id' => $variant->id,
                'quantity' => 2,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Order created')
        ->assertRedirect();

    $jobOrder = JobOrder::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($jobOrder->quotation_id)->toBeNull()
        ->and((float) $jobOrder->total_amount)->toBe(3000.0)
        ->and((int) $jobOrder->items()->firstOrFail()->quantity)->toBe(2);

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
