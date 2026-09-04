<?php

use App\Actions\OpticalOrders\CreateOpticalOrderFromQuotation;
use App\Enums\CommercialItemKind;
use App\Enums\QuotationStatus;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Quotations\Widgets\QuotationStatsWidget;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can list quotations', function () {
    $staff = User::factory()->staff()->create();
    $quotations = Quotation::factory()->count(3)->create();

    $this->actingAs($staff);

    Livewire::test(ListQuotations::class)
        ->assertCanSeeTableRecords($quotations)
        ->assertSee('Draft Value');
});

test('quotation list statistics summarize status counts and draft value', function () {
    $staff = User::factory()->staff()->create();

    Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'total' => 12500,
    ]);
    Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'total' => 3500,
    ]);
    Quotation::factory()->accepted()->create();
    Quotation::factory()->create(['status' => QuotationStatus::Declined]);

    $this->actingAs($staff);

    $widget = Livewire::test(QuotationStatsWidget::class)->instance();
    $stats = collect((fn (): array => $this->getStats())->call($widget))->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats)->toHaveCount(4)
        ->and($stats->get('Draft')?->getValue())->toBe('2')
        ->and($stats->get('Accepted')?->getValue())->toBe('1')
        ->and($stats->get('Declined')?->getValue())->toBe('1')
        ->and($stats->get('Draft Value')?->getValue())->toBe('₱16,000.00');
});

test('the quotations list offers a direct new quotation action that opens the create page', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(ListQuotations::class)
        ->assertActionVisible('create')
        ->assertActionHasUrl('create', QuotationResource::getUrl('create'));
});

test('staff can view a quotation', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Patient Notes')
        ->assertFormFieldDoesNotExist('internal_notes')
        ->assertDontSee('Internal Notes')
        ->assertDontSee('Customer Notes');
});

test('accepted quotation review is read-only and hides workflow stage details', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'valid_until' => now()->addWeek(),
    ]);

    $this->actingAs($staff);

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->assertSuccessful()
        ->assertFormFieldDisabled('valid_until')
        ->assertFormFieldDisabled('notes')
        ->assertActionHidden('confirmSale')
        ->assertActionHidden('reviseItems')
        ->assertFormFieldDoesNotExist('optical_order_number')
        ->assertFormFieldDoesNotExist('billing_record_number')
        ->assertDontSee('Workflow Stages')
        ->assertDontSee('Created after confirmation');
});

test('quotation review links to its optical order and billing record', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Accepted]);
    $order = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'patient_id' => $quotation->patient_id,
    ]);
    $billing = BillingRecord::factory()->create([
        'quotation_id' => $quotation->id,
        'job_order_id' => $order->id,
        'patient_id' => $quotation->patient_id,
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('viewJobOrder')
        ->assertActionVisible('viewBillingRecord');

    expect($component->instance()->getAction('viewJobOrder')->getUrl())
        ->toBe(OpticalOrderResource::getUrl('edit', ['record' => $order]))
        ->and($component->instance()->getAction('viewBillingRecord')->getUrl())
        ->toBe(BillingRecordResource::getUrl('edit', ['record' => $billing]));
});

test('staff can see product and service items on a quotation', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create();
    QuotationItem::factory()->product()->create([
        'quotation_id' => $quotation->id,
        'description' => 'Classic Black Frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Classic Black Frame')
        ->assertSee('4,500.00');
});

test('quotation details show the linked prescription reference and prescriber', function () {
    $staff = User::factory()->staff()->create();
    $author = User::factory()->optometrist()->create([
        'first_name' => 'Lina',
        'middle_name' => null,
        'last_name' => 'Santos',
    ]);
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create([
        'patient_id' => $patient->id,
        'created_by' => $author->id,
    ]);
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'prescription_id' => $prescription->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->assertSee($prescription->prescription_number)
        ->assertSee('Lina Santos')
        ->assertSee('Current');
});

test('confirm sale exposes fulfillment choices and defaults prescription eyewear to pickup preparation', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'patient_id' => $patient->id,
        'prescription_id' => $prescription->id,
        'subtotal' => 8000,
        'total' => 8000,
    ]);

    $quotation->items()->createMany([
        [
            'description' => 'Frame',
            'quantity' => 1,
            'unit_price' => 5000,
            'amount' => 5000,
            'product_variant_id' => $variant->id,
            'item_kind' => CommercialItemKind::Frame,
        ],
        [
            'description' => 'Single Vision Lens',
            'quantity' => 1,
            'unit_price' => 3000,
            'amount' => 3000,
            'lens_category_id' => $lensCategory->id,
            'item_kind' => CommercialItemKind::LensPackage,
        ],
    ]);

    $this->actingAs($staff);

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->mountAction('confirmSale')
        ->assertActionDataSet([
            'fulfillment_mode' => 'prepared',
            'uses_external_supplier' => false,
        ])
        ->setActionData([
            'fulfillment_mode' => 'immediate',
        ])
        ->assertMountedActionModalDontSee('Dispensing Recipient')
        ->setActionData([
            'fulfillment_mode' => 'prepared',
        ])
        ->assertMountedActionModalSee([
            'Fulfillment',
            'Complete sale now',
            'Prepare for pickup',
            'External supplier',
        ]);
});

test('confirm sale saves the selected fulfillment and supplier settings', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'patient_id' => $patient->id,
        'subtotal' => 5000,
        'total' => 5000,
    ]);

    $quotation->items()->create([
        'description' => 'Accessory',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_kind' => CommercialItemKind::Accessory,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->callAction('confirmSale', [
            'fulfillment_mode' => 'prepared',
            'uses_external_supplier' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Sale confirmed');

    $order = JobOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();

    expect($order->fulfillment_mode)->toBe('prepared')
        ->and($order->uses_external_supplier)->toBeTrue();
});

test('quotation resource is registered', function () {
    expect(QuotationResource::getModel())->toBe(Quotation::class);
});

test('staff revises a draft quotation\'s items', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 4500,
        'total' => 4500,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->assertActionVisible('reviseItems')
        ->callAction('reviseItems', [
            'items' => [[
                'item_kind' => 'custom_service',
                'description' => 'Adjusted eye exam fee',
                'quantity' => 1,
                'unit_price' => 800,
            ]],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $quotation->refresh();

    expect($quotation->items)->toHaveCount(1)
        ->and($quotation->items->first()->description)->toBe('Adjusted eye exam fee')
        ->and($quotation->total)->toBe('800.00');
});

test('prescription revisions open a wide slide-over with the dedicated quotation builder', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'patient_id' => $patient->id,
        'prescription_id' => $prescription->id,
        'subtotal' => 3000,
        'total' => 3000,
    ]);
    $quotation->items()->create([
        'description' => $lensCategory->name,
        'quantity' => 1,
        'unit_price' => $lensCategory->price,
        'amount' => $lensCategory->price,
        'lens_category_id' => $lensCategory->id,
        'item_kind' => CommercialItemKind::LensPackage,
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()]);
    $action = $component->instance()->getAction('reviseItems');

    expect($action)->not->toBeNull()
        ->and($action->isModalSlideOver())->toBeTrue()
        ->and($action->getModalWidth()->value)->toBe('7xl');

    $component
        ->mountAction('reviseItems')
        ->assertActionMounted('reviseItems')
        ->assertMountedActionModalSee([
            $quotation->quotation_number,
            $patient->full_name,
            $prescription->prescription_number,
            'Draft',
            'Current total',
            'Prescription Eyewear',
            'Save Revision',
        ])
        ->assertActionDataSet(['eyewear_lens_category_id' => $lensCategory->id]);
});

test('staff can save a dedicated prescription eyewear revision with other items', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);
    $frame = Product::factory()->create([
        'name' => 'Aster Frame',
        'product_type' => 'frame',
    ]);
    $frameVariant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'name' => 'Matte Black',
        'sku' => 'FRM-AST-BLK',
        'price' => 2450,
    ]);
    $lensCategory = LensCategory::factory()->withPrice(1800)->create();
    $lensOption = LensOption::factory()->create(['price' => 600]);
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'patient_id' => $patient->id,
        'prescription_id' => $prescription->id,
    ]);
    $quotation->items()->create([
        'description' => 'Existing lens package',
        'quantity' => 1,
        'unit_price' => $lensCategory->price,
        'amount' => $lensCategory->price,
        'lens_category_id' => $lensCategory->id,
        'item_kind' => CommercialItemKind::LensPackage,
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->mountAction('reviseItems')
        ->assertActionDataSet(['eyewear_lens_category_id' => $lensCategory->id]);

    $component
        ->fillForm([
            'eyewear_frame_source' => 'catalog',
            'eyewear_frame_variant_id' => $frameVariant->id,
            'eyewear_lens_category_id' => $lensCategory->id,
            'eyewear_lens_options' => [['lens_option_id' => $lensOption->id]],
            'items' => [[
                'item_kind' => 'custom_service',
                'description' => 'Eye examination',
                'quantity' => 1,
                'unit_price' => 500,
            ]],
        ])
        ->assertActionDataSet([
            'eyewear_frame_source' => 'catalog',
            'eyewear_frame_variant_id' => $frameVariant->id,
            'eyewear_lens_category_id' => $lensCategory->id,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Quotation revised');

    $quotation->refresh();

    expect($quotation->items)->toHaveCount(4)
        ->and($quotation->items->pluck('description')->all())->toContain(
            'Aster Frame — Matte Black',
            $lensCategory->name,
            $lensOption->name,
            'Eye examination',
        )
        ->and((float) $quotation->total)->toBe(5350.0);
});

test('opening revise items pre-fills the existing item', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);
    QuotationItem::factory()->product()->create([
        'quotation_id' => $quotation->id,
        'description' => 'Classic Black Frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->mountAction('reviseItems')
        ->assertSee('Classic Black Frame');
});

test('the revise items action is hidden once a quotation has an optical order', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);
    QuotationItem::factory()->product()->create([
        'quotation_id' => $quotation->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $staff,
    );

    $this->actingAs($staff);

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->assertActionHidden('reviseItems')
        ->assertActionHidden('confirmSale');
});
