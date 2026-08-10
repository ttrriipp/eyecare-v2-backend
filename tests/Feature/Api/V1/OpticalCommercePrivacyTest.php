<?php

/**
 * Tests for optical commerce API privacy.
 *
 * @see tasks/todo.md Task 40
 */

use App\Enums\QuotationStatus;
use App\Models\JobOrder;
use App\Models\JobOrderEyewearSpecification;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->patient = User::factory()->patient()->create();
});

test('patient resources retain descriptions, quantities, prices, and status', function () {
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $this->patient->patient->id,
        'status' => 'queued',
    ]);

    $response = $this->actingAs($this->patient)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertOk();

    $response->assertJsonStructure([
        'data' => [
            'id',
            'order_number',
            'status',
            'total_amount',
            'items' => [
                '*' => ['id', 'description', 'quantity', 'unit_price', 'amount'],
            ],
        ],
    ]);
});

test('eyewear measurements are absent from patient resources', function () {
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $this->patient->patient->id,
    ]);

    // Create specification with measurements
    $spec = JobOrderEyewearSpecification::factory()->create([
        'job_order_id' => $jobOrder->id,
        'distance_pd_binocular' => '62.5',
        'fitting_height_od' => '22.0',
        'lab_instructions' => 'Standard coating',
    ]);

    $response = $this->actingAs($this->patient)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertOk();

    $response->assertJsonMissing([
        'distance_pd_binocular',
        'fitting_height_od',
        'lab_instructions',
        'approved_by',
        'verified_by',
    ]);
});

test('supplier references are absent from patient resources', function () {
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $this->patient->patient->id,
        'supplier_invoice_number' => 'INV-001',
        'uses_external_supplier' => true,
    ]);

    $response = $this->actingAs($this->patient)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertOk();

    $response->assertJsonMissing([
        'supplier_invoice_number',
        'uses_external_supplier',
    ]);
});

test('override reason is absent from patient resources', function () {
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $this->patient->patient->id,
    ]);

    $response = $this->actingAs($this->patient)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertOk();

    $response->assertJsonMissing([
        'balance_override_reason',
        'balance_override_by',
    ]);
});

test('ownership scoping remains intact', function () {
    $otherPatient = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $this->patient->patient->id,
    ]);

    $this->actingAs($otherPatient)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertNotFound();
});

test('draft quotations are not visible to patients', function () {
    $quotation = Quotation::factory()->create([
        'patient_id' => $this->patient->patient->id,
        'status' => QuotationStatus::Draft,
    ]);

    $response = $this->actingAs($this->patient)
        ->getJson('/api/v1/quotations')
        ->assertOk();

    $response->assertJsonPath('meta.total', 0);
});

test('presented quotations are visible to patients', function () {
    $quotation = Quotation::factory()->presented()->create([
        'patient_id' => $this->patient->patient->id,
    ]);

    $response = $this->actingAs($this->patient)
        ->getJson('/api/v1/quotations')
        ->assertOk();

    $response->assertJsonPath('meta.total', 1);
});
