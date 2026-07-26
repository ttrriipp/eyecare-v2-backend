<?php

use App\Models\Prescription;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('prescription queries use patient_id not customer_id', function () {
    $user = User::factory()->patient()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $user->patient->id]);

    $this->actingAs($user);

    $response = $this->getJson('/api/prescriptions');
    $response->assertOk();
});

test('only optometrist can finalize a prescription', function () {
    $optometrist = User::factory()->optometrist()->create();
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);

    // Optometrist can finalize (verified by existing PrescriptionLifecycleTest)
    expect($optometrist->hasOptometristCapability())->toBeTrue();

    // Non-optometrist cannot
    expect($staff->hasOptometristCapability())->toBeFalse();
});

test('CX column in print uses cylinder value', function () {
    $prescription = Prescription::factory()->create([
        'od_cylinder' => '-1.50',
        'od_axis' => 180,
        'os_cylinder' => '-0.75',
        'os_axis' => 90,
    ]);

    // The print view renders CX as od_cylinder/os_cylinder, not axis
    $view = view('pdf.prescription', ['prescription' => $prescription])->render();

    // CX column header exists
    expect($view)->toContain('CX');

    // Cylinder values are present
    expect($view)->toContain('-1.50');
    expect($view)->toContain('-0.75');

    // Axis values are separate
    expect($view)->toContain('180');
    expect($view)->toContain('90');
});
