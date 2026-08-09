<?php

/**
 * Tests for ApproveEyewearSpecification action.
 *
 * @see tasks/todo.md Task 19
 */

use App\Actions\JobOrders\ApproveEyewearSpecification;
use App\Models\JobOrderEyewearSpecification;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->optometrist = User::factory()->optometrist()->create();
    $this->staff = User::factory()->staff()->create();
    $this->admin = User::factory()->admin()->create();
});

test('active optometrist may approve a complete specification', function () {
    $spec = JobOrderEyewearSpecification::factory()->create([
        'lens_design_snapshot' => 'Progressive',
    ]);

    $result = app(ApproveEyewearSpecification::class)->handle($spec, $this->optometrist);

    expect($result->isApproved())->toBeTrue()
        ->and($result->approved_by)->toBe($this->optometrist->id)
        ->and($result->approved_at)->not->toBeNull();
});

test('dual-role owner can approve', function () {
    $dualRole = User::factory()->adminOptometrist()->create();

    $spec = JobOrderEyewearSpecification::factory()->create([
        'lens_design_snapshot' => 'Progressive',
    ]);

    $result = app(ApproveEyewearSpecification::class)->handle($spec, $dualRole);

    expect($result->isApproved())->toBeTrue();
});

test('staff cannot approve', function () {
    $spec = JobOrderEyewearSpecification::factory()->create([
        'lens_design_snapshot' => 'Progressive',
    ]);

    app(ApproveEyewearSpecification::class)->handle($spec, $this->staff);
})->throws(ValidationException::class, 'Only an active optometrist');

test('plain admin cannot approve', function () {
    $spec = JobOrderEyewearSpecification::factory()->create([
        'lens_design_snapshot' => 'Progressive',
    ]);

    app(ApproveEyewearSpecification::class)->handle($spec, $this->admin);
})->throws(ValidationException::class, 'Only an active optometrist');

test('approval requires lens design', function () {
    $spec = JobOrderEyewearSpecification::factory()->create([
        'lens_design_snapshot' => null,
    ]);

    app(ApproveEyewearSpecification::class)->handle($spec, $this->optometrist);
})->throws(ValidationException::class, 'Complete the lens design');

test('approval creates audit log', function () {
    $spec = JobOrderEyewearSpecification::factory()->create([
        'lens_design_snapshot' => 'Progressive',
    ]);

    app(ApproveEyewearSpecification::class)->handle($spec, $this->optometrist);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'eyewear_specification.approved',
        'actor_id' => $this->optometrist->id,
    ]);
});
