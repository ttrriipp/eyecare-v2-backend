<?php

/**
 * Tests for JobOrderEyewearSpecification model.
 *
 * @see tasks/todo.md Task 15
 */

use App\Enums\FrameSource;
use App\Models\JobOrder;
use App\Models\JobOrderEyewearSpecification;
use App\Models\JobOrderItem;
use App\Models\Prescription;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('one job order may have at most one eyewear specification', function () {
    $jobOrder = JobOrder::factory()->create();
    $prescription = Prescription::factory()->create();
    $lensItem = JobOrderItem::factory()->lensPackage()->create(['job_order_id' => $jobOrder->id]);

    JobOrderEyewearSpecification::factory()->create([
        'job_order_id' => $jobOrder->id,
        'prescription_id' => $prescription->id,
        'lens_package_job_order_item_id' => $lensItem->id,
    ]);

    // Second specification for same job order should fail
    JobOrderEyewearSpecification::factory()->create([
        'job_order_id' => $jobOrder->id,
        'prescription_id' => $prescription->id,
        'lens_package_job_order_item_id' => $lensItem->id,
    ]);
})->throws(QueryException::class);

test('specification references prescription and relevant items', function () {
    $jobOrder = JobOrder::factory()->create();
    $prescription = Prescription::factory()->create();
    $frameItem = JobOrderItem::factory()->frame()->create(['job_order_id' => $jobOrder->id]);
    $lensItem = JobOrderItem::factory()->lensPackage()->create(['job_order_id' => $jobOrder->id]);

    $spec = JobOrderEyewearSpecification::factory()->create([
        'job_order_id' => $jobOrder->id,
        'prescription_id' => $prescription->id,
        'frame_job_order_item_id' => $frameItem->id,
        'lens_package_job_order_item_id' => $lensItem->id,
        'frame_source' => FrameSource::Catalog,
    ]);

    expect($spec->jobOrder->id)->toBe($jobOrder->id)
        ->and($spec->prescription->id)->toBe($prescription->id)
        ->and($spec->frameItem->id)->toBe($frameItem->id)
        ->and($spec->lensPackageItem->id)->toBe($lensItem->id)
        ->and($spec->frame_source)->toBe(FrameSource::Catalog);
});

test('measurements use encrypted storage with null defaults', function () {
    $spec = JobOrderEyewearSpecification::factory()->create([
        'distance_pd_binocular' => '62.5',
        'fitting_height_od' => '22.0',
        'lab_instructions' => 'Standard anti-reflective coating',
    ]);

    expect($spec->distance_pd_binocular)->toBe('62.5')
        ->and($spec->fitting_height_od)->toBe('22.0')
        ->and($spec->lab_instructions)->toBe('Standard anti-reflective coating')
        ->and($spec->distance_pd_od)->toBeNull()
        ->and($spec->near_pd_binocular)->toBeNull()
        ->and($spec->segment_height_od)->toBeNull();
});

test('approval attribution uses nullable foreign keys and timestamps', function () {
    $approver = User::factory()->optometrist()->create();

    $spec = JobOrderEyewearSpecification::factory()->approved()->create([
        'approved_by' => $approver->id,
    ]);

    expect($spec->isApproved())->toBeTrue()
        ->and($spec->approver->id)->toBe($approver->id)
        ->and($spec->approved_at)->not->toBeNull();
});

test('verification attribution uses nullable foreign keys and timestamps', function () {
    $verifier = User::factory()->staff()->create();

    $spec = JobOrderEyewearSpecification::factory()->verified()->create([
        'verified_by' => $verifier->id,
    ]);

    expect($spec->isVerified())->toBeTrue()
        ->and($spec->verifier->id)->toBe($verifier->id)
        ->and($spec->verified_at)->not->toBeNull();
});

test('frame source can be catalog or patient supplied', function () {
    $catalog = JobOrderEyewearSpecification::factory()->create([
        'frame_source' => FrameSource::Catalog,
    ]);

    $patientSupplied = JobOrderEyewearSpecification::factory()->patientSupplied()->create();

    expect($catalog->frame_source)->toBe(FrameSource::Catalog)
        ->and($patientSupplied->frame_source)->toBe(FrameSource::PatientSupplied);
});

test('job order has one eyewear specification relationship', function () {
    $jobOrder = JobOrder::factory()->create();
    $spec = JobOrderEyewearSpecification::factory()->create(['job_order_id' => $jobOrder->id]);

    expect($jobOrder->eyewearSpecification->id)->toBe($spec->id);
});
