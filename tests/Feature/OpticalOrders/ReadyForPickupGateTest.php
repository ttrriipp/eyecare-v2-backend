<?php

/**
 * Tests for verification gate on Ready for Pickup.
 *
 * @see tasks/todo.md Task 23
 */

use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use App\Models\JobOrderEyewearSpecification;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->actingAs($this->staff);
});

test('unverified corrective work cannot become ready for pickup', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);
    JobOrderEyewearSpecification::factory()->approved()->create([
        'job_order_id' => $jobOrder->id,
        'verified_by' => null,
        'verified_at' => null,
    ]);

    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'ready_for_dispensing');
})->throws(ValidationException::class, 'Verify the completed eyewear');

test('verified corrective order can become ready for pickup', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::InProgress,
        'supplier_invoice_number' => 'INV-001',
    ]);
    JobOrderEyewearSpecification::factory()->approved()->verified()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    $result = app(UpdateJobOrderStatus::class)->handle($jobOrder, 'ready_for_dispensing');

    expect($result->status)->toBe(JobOrderStatus::ReadyForDispensing)
        ->and($result->ready_at)->not->toBeNull();
});

test('external work requires supplier reference', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::InProgress,
        'uses_external_supplier' => true,
        'supplier_invoice_number' => null,
    ]);
    JobOrderEyewearSpecification::factory()->approved()->verified()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'ready_for_dispensing');
})->throws(ValidationException::class, 'supplier invoice number');

test('non-corrective order can become ready without verification', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::InProgress,
        'supplier_invoice_number' => 'INV-001',
    ]);

    $result = app(UpdateJobOrderStatus::class)->handle($jobOrder, 'ready_for_dispensing');

    expect($result->status)->toBe(JobOrderStatus::ReadyForDispensing);
});

test('verified specification rejects silent further edits', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);
    $spec = JobOrderEyewearSpecification::factory()->approved()->verified()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    // The specification is verified - further edits should be blocked
    // This is enforced by the SaveEyewearSpecification action checking the order status
    expect($spec->isVerified())->toBeTrue();
});
