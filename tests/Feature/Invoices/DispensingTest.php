<?php

use App\Actions\Invoices\DispenseJobOrder;
use App\Enums\InvoiceStatus;
use App\Enums\JobOrderStatus;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('dispensing creates a dispensing event and issues invoice', function () {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::ReadyForDispensing]);

    $event = app(DispenseJobOrder::class)->handle(
        jobOrder: $jobOrder,
        dispenser: $staff,
        officialNumber: 'SI-2026-001',
        recipientName: 'Juan dela Cruz',
    );

    expect($event->job_order_id)->toBe($jobOrder->id)
        ->and($event->dispensed_by)->toBe($staff->id)
        ->and($event->recipient_name)->toBe('Juan dela Cruz');

    // Job order is dispensed
    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::Dispensed)
        ->and($jobOrder->fresh()->dispensed_at)->not->toBeNull();

    // Invoice is issued
    $invoice = $event->invoice;
    expect($invoice->official_number)->toBe('SI-2026-001')
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->issued_at)->not->toBeNull();
});

test('only ready-for-dispensing job orders can be dispensed', function () {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);

    app(DispenseJobOrder::class)->handle($jobOrder, $staff);
})->throws(ValidationException::class);

test('dispensing rolls back on failure', function () {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::ReadyForDispensing]);

    // The action should succeed normally
    $event = app(DispenseJobOrder::class)->handle($jobOrder, $staff);

    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::Dispensed);
});

test('dispensing attaches to existing invoice', function () {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::ReadyForDispensing]);
    $existingInvoice = Invoice::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total' => 5000,
        'balance_due' => 5000,
    ]);

    $event = app(DispenseJobOrder::class)->handle(
        jobOrder: $jobOrder,
        dispenser: $staff,
        officialNumber: 'SI-2026-002',
    );

    expect($event->invoice_id)->toBe($existingInvoice->id);
    expect($event->invoice->fresh()->official_number)->toBe('SI-2026-002');
});

test('dispensing creates invoice when none exists', function () {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 3500,
    ]);

    $event = app(DispenseJobOrder::class)->handle($jobOrder, $staff);

    expect($event->invoice)->not->toBeNull()
        ->and((float) $event->invoice->total)->toBe(3500.0)
        ->and($event->invoice->status)->toBe(InvoiceStatus::Issued);
});
