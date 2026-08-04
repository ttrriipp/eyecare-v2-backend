<?php

use App\Enums\QuotationStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\JobOrder;
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

test('quotation table shows status badges', function () {
    $staff = User::factory()->staff()->create();
    $draft = Quotation::factory()->create(['status' => QuotationStatus::Draft]);
    $presented = Quotation::factory()->create(['status' => QuotationStatus::Presented]);

    $this->actingAs($staff);

    Livewire::test(ListQuotations::class)
        ->assertTableColumnStateSet('status', QuotationStatus::Draft, record: $draft)
        ->assertTableColumnStateSet('status', QuotationStatus::Presented, record: $presented);
});

test('staff confirms the sale of an accepted quotation and creates an optical order', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Accepted]);
    QuotationItem::factory()->product()->create([
        'quotation_id' => $quotation->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->assertActionVisible('confirmSale')
        ->callAction('confirmSale')
        ->assertNotified()
        ->assertRedirect();

    $jobOrder = JobOrder::query()
        ->where('quotation_id', $quotation->id)
        ->firstOrFail();

    expect(OpticalOrderResource::getUrl('edit', ['record' => $jobOrder]))
        ->toContain("/optical-orders/{$jobOrder->id}/edit");
});

test('quotation resource is registered', function () {
    expect(QuotationResource::getModel())->toBe(Quotation::class);
});
