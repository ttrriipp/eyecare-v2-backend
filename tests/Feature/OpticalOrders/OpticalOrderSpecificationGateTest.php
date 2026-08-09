<?php

/**
 * Tests for specification approval gate on Processing.
 *
 * @see tasks/todo.md Task 21
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

test('corrective order cannot start processing without approved specification', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    JobOrderEyewearSpecification::factory()->create([
        'job_order_id' => $jobOrder->id,
        'approved_by' => null,
        'approved_at' => null,
    ]);

    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'in_progress');
})->throws(ValidationException::class, 'Approve the eyewear specification');

test('approved corrective order can start processing', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    JobOrderEyewearSpecification::factory()->approved()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    $result = app(UpdateJobOrderStatus::class)->handle($jobOrder, 'in_progress');

    expect($result->status)->toBe(JobOrderStatus::InProgress)
        ->and($result->started_at)->not->toBeNull();
});

test('non-corrective order can start processing without specification', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);

    $result = app(UpdateJobOrderStatus::class)->handle($jobOrder, 'in_progress');

    expect($result->status)->toBe(JobOrderStatus::InProgress);
});

test('immediate order behavior remains unchanged', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
        'fulfillment_mode' => 'immediate',
    ]);

    $result = app(UpdateJobOrderStatus::class)->handle($jobOrder, 'in_progress');

    expect($result->status)->toBe(JobOrderStatus::InProgress);
});
