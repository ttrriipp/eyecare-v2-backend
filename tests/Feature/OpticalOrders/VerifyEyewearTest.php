<?php

/**
 * Tests for VerifyEyewear action.
 *
 * @see tasks/todo.md Task 22
 */

use App\Actions\JobOrders\VerifyEyewear;
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

test('only approved in-processing order can be verified', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);
    $spec = JobOrderEyewearSpecification::factory()->approved()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    $result = app(VerifyEyewear::class)->handle($jobOrder, $this->staff, 'Looks good');

    expect($result->isVerified())->toBeTrue()
        ->and($result->verified_by)->toBe($this->staff->id)
        ->and($result->verified_at)->not->toBeNull()
        ->and($result->verification_notes)->toBe('Looks good');
});

test('verification records actor and time', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);
    $spec = JobOrderEyewearSpecification::factory()->approved()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    $result = app(VerifyEyewear::class)->handle($jobOrder, $this->staff);

    expect($result->verified_by)->toBe($this->staff->id)
        ->and($result->verified_at)->not->toBeNull();
});

test('verification does not modify prescription values', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);
    $spec = JobOrderEyewearSpecification::factory()->approved()->create([
        'job_order_id' => $jobOrder->id,
        'lens_design_snapshot' => 'Progressive',
    ]);

    app(VerifyEyewear::class)->handle($jobOrder, $this->staff);

    $spec->refresh();
    expect($spec->lens_design_snapshot)->toBe('Progressive');
});

test('retry does not create duplicate verification', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);
    $spec = JobOrderEyewearSpecification::factory()->approved()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    $first = app(VerifyEyewear::class)->handle($jobOrder, $this->staff);
    $second = app(VerifyEyewear::class)->handle($jobOrder, $this->staff);

    expect($first->id)->toBe($second->id)
        ->and($first->verified_by)->toBe($second->verified_by);
});

test('queued order cannot be verified', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    JobOrderEyewearSpecification::factory()->approved()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    app(VerifyEyewear::class)->handle($jobOrder, $this->staff);
})->throws(ValidationException::class, 'Only in-processing');

test('unapproved specification cannot be verified', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);
    JobOrderEyewearSpecification::factory()->create([
        'job_order_id' => $jobOrder->id,
        'approved_by' => null,
        'approved_at' => null,
    ]);

    app(VerifyEyewear::class)->handle($jobOrder, $this->staff);
})->throws(ValidationException::class, 'must be approved');

test('verification creates audit log', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);
    JobOrderEyewearSpecification::factory()->approved()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    app(VerifyEyewear::class)->handle($jobOrder, $this->staff);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'eyewear_specification.verified',
        'actor_id' => $this->staff->id,
    ]);
});
