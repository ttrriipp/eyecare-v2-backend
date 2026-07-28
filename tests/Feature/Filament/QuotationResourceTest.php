<?php

use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
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
        ->assertDontSee('Customer Notes');
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

test('quotation resource is registered', function () {
    expect(QuotationResource::getModel())->toBe(Quotation::class);
});
