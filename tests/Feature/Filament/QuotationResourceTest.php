<?php

use App\Actions\OpticalOrders\CreateOpticalOrderFromQuotation;
use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can list quotations', function () {
    $staff = User::factory()->staff()->create();
    $quotations = Quotation::factory()->count(3)->create();

    $this->actingAs($staff);

    Livewire::test(ListQuotations::class)
        ->assertCanSeeTableRecords($quotations);
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

test('accepted quotation review is read-only and shows future workflow stages', function () {
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
        ->assertSee('Created after confirmation');
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

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($order->job_order_number)
        ->assertSee($billing->billing_record_number)
        ->assertActionVisible('viewJobOrder')
        ->assertActionVisible('viewBillingRecord');
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
