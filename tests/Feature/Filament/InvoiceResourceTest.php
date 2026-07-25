<?php

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can list invoices', function () {
    $staff = User::factory()->staff()->create();
    $invoices = Invoice::factory()->count(3)->create();

    $this->actingAs($staff);

    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords($invoices);
});

test('staff can view an invoice', function () {
    $staff = User::factory()->staff()->create();
    $invoice = Invoice::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertSuccessful();
});

test('invoice resource is registered', function () {
    expect(InvoiceResource::getModel())->toBe(Invoice::class);
});
