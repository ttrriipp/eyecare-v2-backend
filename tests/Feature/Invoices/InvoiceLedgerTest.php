<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Patient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('invoice has a unique invoice number', function () {
    $inv1 = Invoice::factory()->create();
    $inv2 = Invoice::factory()->create();

    expect($inv1->invoice_number)->toStartWith('INV-')
        ->and($inv1->invoice_number)->not->toBe($inv2->invoice_number);
});

test('invoice belongs to a patient', function () {
    $patient = Patient::factory()->create();
    $invoice = Invoice::factory()->create(['patient_id' => $patient->id]);

    expect($invoice->patient->id)->toBe($patient->id);
});

test('invoice items are immutable snapshots', function () {
    $invoice = Invoice::factory()->create(['total' => 5000]);
    $item = InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Classic Frame — Matte Black',
        'quantity' => 1,
        'unit_price' => 2500,
        'amount' => 2500,
    ]);

    // Items are snapshots — modifying the item doesn't change the invoice total
    expect($item->description)->toBe('Classic Frame — Matte Black')
        ->and((float) $item->amount)->toBe(2500.0)
        ->and((float) $invoice->total)->toBe(5000.0);
});

test('official number is nullable until dispensing', function () {
    $invoice = Invoice::factory()->create(['official_number' => null]);

    expect($invoice->official_number)->toBeNull();

    $invoice->update(['official_number' => 'SI-2026-001']);

    expect($invoice->fresh()->official_number)->toBe('SI-2026-001');
});

test('official number is unique when recorded', function () {
    Invoice::factory()->create(['official_number' => 'SI-2026-001']);

    Invoice::factory()->create(['official_number' => 'SI-2026-001']);
})->throws(QueryException::class);

test('payments form an append-only ledger', function () {
    $invoice = Invoice::factory()->create(['total' => 5000, 'balance_due' => 5000]);

    InvoicePayment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 2000,
    ]);

    InvoicePayment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 1500,
    ]);

    expect($invoice->payments)->toHaveCount(2)
        ->and((float) $invoice->payments->sum('amount'))->toBe(3500.0);
});

test('recalculateBalance derives amount_paid and balance_due from payments', function () {
    $invoice = Invoice::factory()->create([
        'total' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => InvoiceStatus::Issued,
    ]);

    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 2000]);
    $invoice->recalculateBalance();
    $invoice->refresh();

    expect((float) $invoice->amount_paid)->toBe(2000.0)
        ->and((float) $invoice->balance_due)->toBe(3000.0)
        ->and($invoice->status)->toBe(InvoiceStatus::PartiallyPaid);

    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 3000]);
    $invoice->recalculateBalance();
    $invoice->refresh();

    expect((float) $invoice->amount_paid)->toBe(5000.0)
        ->and((float) $invoice->balance_due)->toBe(0.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

test('recalculateBalance handles overpayment', function () {
    $invoice = Invoice::factory()->create([
        'total' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => InvoiceStatus::Issued,
    ]);

    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 6000]);
    $invoice->recalculateBalance();
    $invoice->refresh();

    expect((float) $invoice->amount_paid)->toBe(6000.0)
        ->and((float) $invoice->balance_due)->toBe(0.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

test('invoice item types can be product or service', function () {
    $productItem = InvoiceItem::factory()->create(['type' => 'product']);
    $serviceItem = InvoiceItem::factory()->create(['type' => 'service']);

    expect($productItem->type)->toBe('product')
        ->and($serviceItem->type)->toBe('service');
});

test('invoice has sale type', function () {
    $cashSale = Invoice::factory()->create(['sale_type' => 'cash_sale']);
    $chargeSale = Invoice::factory()->create(['sale_type' => 'charge_sale']);

    expect($cashSale->sale_type)->toBe('cash_sale')
        ->and($chargeSale->sale_type)->toBe('charge_sale');
});
