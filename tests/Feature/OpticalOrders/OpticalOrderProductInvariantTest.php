<?php

/**
 * Tests for Optical Order Product-only invariant.
 */

use App\Enums\QuotationStatus;
use App\Models\JobOrder;
use App\Models\Quotation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('one quotation creates at most one job order', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);

    // Create first job order
    JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'patient_id' => $quotation->patient_id,
    ]);

    // Try to create second job order for same quotation
    $this->expectException(QueryException::class);

    JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'patient_id' => $quotation->patient_id,
    ]);
});

test('Service-only quotation may have no job order', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'total' => 1500,
    ]);

    // No job order created for service-only quotation
    expect($quotation->jobOrder)->toBeNull();
});
