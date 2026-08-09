<?php

/**
 * Tests for SaveEyewearSpecification action.
 *
 * @see tasks/todo.md Task 17
 */

use App\Actions\JobOrders\SaveEyewearSpecification;
use App\Enums\FrameSource;
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

test('accepts binocular distance PD', function () {
    $spec = JobOrderEyewearSpecification::factory()->create();

    $result = app(SaveEyewearSpecification::class)->handle($spec, [
        'distance_pd_mode' => 'binocular',
        'distance_pd_binocular' => 62.5,
    ], $this->staff);

    expect($result->distance_pd_mode)->toBe('binocular')
        ->and((float) $result->distance_pd_binocular)->toBe(62.5);
});

test('accepts both monocular distance PD values', function () {
    $spec = JobOrderEyewearSpecification::factory()->create();

    $result = app(SaveEyewearSpecification::class)->handle($spec, [
        'distance_pd_mode' => 'monocular',
        'distance_pd_od' => 31.0,
        'distance_pd_os' => 31.5,
    ], $this->staff);

    expect($result->distance_pd_mode)->toBe('monocular')
        ->and((float) $result->distance_pd_od)->toBe(31.0)
        ->and((float) $result->distance_pd_os)->toBe(31.5);
});

test('rejects mixed PD representation', function () {
    $spec = JobOrderEyewearSpecification::factory()->create();

    app(SaveEyewearSpecification::class)->handle($spec, [
        'distance_pd_mode' => 'binocular',
        'distance_pd_binocular' => 62.5,
        'distance_pd_od' => 31.0,
    ], $this->staff);
})->throws(ValidationException::class, 'not both');

test('saves lens construction snapshots', function () {
    $spec = JobOrderEyewearSpecification::factory()->create();

    $result = app(SaveEyewearSpecification::class)->handle($spec, [
        'lens_design_snapshot' => 'Progressive',
        'lens_material_snapshot' => 'Polycarbonate',
        'refractive_index_snapshot' => '1.59',
        'lens_options_snapshot' => ['anti-reflective', 'photochromic'],
    ], $this->staff);

    expect($result->lens_design_snapshot)->toBe('Progressive')
        ->and($result->lens_material_snapshot)->toBe('Polycarbonate')
        ->and($result->refractive_index_snapshot)->toBe('1.59')
        ->and($result->lens_options_snapshot)->toBe(['anti-reflective', 'photochromic']);
});

test('saves lab instructions', function () {
    $spec = JobOrderEyewearSpecification::factory()->create();

    $result = app(SaveEyewearSpecification::class)->handle($spec, [
        'lab_instructions' => 'Standard anti-reflective coating',
    ], $this->staff);

    expect($result->lab_instructions)->toBe('Standard anti-reflective coating');
});

test('saves frame source', function () {
    $spec = JobOrderEyewearSpecification::factory()->create();

    $result = app(SaveEyewearSpecification::class)->handle($spec, [
        'frame_source' => 'patient_supplied',
    ], $this->staff);

    expect($result->frame_source)->toBe(FrameSource::PatientSupplied);
});

test('editing clears approval', function () {
    $spec = JobOrderEyewearSpecification::factory()->approved()->create();

    expect($spec->isApproved())->toBeTrue();

    $result = app(SaveEyewearSpecification::class)->handle($spec, [
        'lens_design_snapshot' => 'Updated design',
    ], $this->staff);

    expect($result->isApproved())->toBeFalse()
        ->and($result->approved_by)->toBeNull()
        ->and($result->approved_at)->toBeNull();
});

test('blank optional measurements remain null', function () {
    $spec = JobOrderEyewearSpecification::factory()->create();

    $result = app(SaveEyewearSpecification::class)->handle($spec, [
        'distance_pd_mode' => 'binocular',
        'distance_pd_binocular' => 62.5,
    ], $this->staff);

    expect($result->near_pd_binocular)->toBeNull()
        ->and($result->fitting_height_od)->toBeNull()
        ->and($result->segment_height_os)->toBeNull();
});

test('validates plausible PD range', function () {
    $spec = JobOrderEyewearSpecification::factory()->create();

    app(SaveEyewearSpecification::class)->handle($spec, [
        'distance_pd_mode' => 'binocular',
        'distance_pd_binocular' => 200, // implausible
    ], $this->staff);
})->throws(ValidationException::class);
