<?php

/**
 * Tests for conditional supplier invoice enforcement.
 */

use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->action = app(UpdateJobOrderStatus::class);
});

test('external prepared work requires supplier invoice for ready', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::InProgress,
        'fulfillment_mode' => 'prepared',
        'uses_external_supplier' => true,
        'supplier_invoice_number' => null,
    ]);

    $this->action->handle($jobOrder, 'ready_for_dispensing');
})->throws(ValidationException::class, 'supplier invoice number');

test('in-house prepared work does not require supplier invoice', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::InProgress,
        'fulfillment_mode' => 'prepared',
        'uses_external_supplier' => false,
        'supplier_invoice_number' => null,
    ]);

    $result = $this->action->handle($jobOrder, 'ready_for_dispensing');

    expect($result->status)->toBe(JobOrderStatus::ReadyForDispensing);
});

test('immediate work does not require supplier invoice', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::InProgress,
        'fulfillment_mode' => 'immediate',
        'uses_external_supplier' => false,
        'supplier_invoice_number' => null,
    ]);

    $result = $this->action->handle($jobOrder, 'ready_for_dispensing');

    expect($result->status)->toBe(JobOrderStatus::ReadyForDispensing);
});

test('external work with supplier invoice can proceed', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::InProgress,
        'fulfillment_mode' => 'prepared',
        'uses_external_supplier' => true,
        'supplier_invoice_number' => 'INV-001',
    ]);

    $result = $this->action->handle($jobOrder, 'ready_for_dispensing');

    expect($result->status)->toBe(JobOrderStatus::ReadyForDispensing);
});
