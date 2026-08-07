<?php

use App\Models\JobOrder;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('prescriptions list is paginated and patient-scoped', function () {
    $user = User::factory()->patient()->create();
    Prescription::factory()->count(5)->create(['patient_id' => $user->patient->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/prescriptions')
        ->assertOk()
        ->assertJsonPath('meta.total', 5);
});

test('prescription responses omit unsupported prism and base fields', function () {
    $user = User::factory()->patient()->create();
    Prescription::factory()->create(['patient_id' => $user->patient->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/prescriptions')
        ->assertOk();

    expect($response->json('data.0'))->not->toHaveKeys([
        'od_prism',
        'od_base',
        'os_prism',
        'os_base',
    ]);
});

test('prescription response contains grouped measurements and remarks', function () {
    $user = User::factory()->patient()->create();
    Prescription::factory()->create([
        'patient_id' => $user->patient->id,
        'main_od_sphere' => '-2.00',
        'main_od_cylinder' => '-0.50',
        'remarks' => 'Test remarks',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/prescriptions')
        ->assertOk();

    expect($response->json('data.0.measurements.main.od.sphere'))->toBe('-2.00')
        ->and($response->json('data.0.measurements.main.od.cylinder'))->toBe('-0.50')
        ->and($response->json('data.0.remarks'))->toBe('Test remarks')
        ->and($response->json('data.0'))->not->toHaveKeys(['od_sphere', 'pd', 'expires_at', 'notes']);
});

test('prescription list returns only current versions with amendment context', function () {
    $user = User::factory()->patient()->create();
    $original = Prescription::factory()->create([
        'patient_id' => $user->patient->id,
        'prescribed_at' => now(),
    ]);
    $amendment = Prescription::factory()->create([
        'patient_id' => $user->patient->id,
        'previous_prescription_id' => $original->id,
        'prescribed_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/prescriptions')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $amendment->id)
        ->assertJsonPath('data.0.previous_prescription_id', $original->id)
        ->assertJsonPath('data.0.is_current', true);

    $this->getJson("/api/v1/prescriptions/{$original->id}")
        ->assertOk()
        ->assertJsonPath('data.is_current', false);
});

test('quotations list is paginated and patient-scoped', function () {
    $user = User::factory()->patient()->create();
    Quotation::factory()->presented()->count(3)->create(['patient_id' => $user->patient->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/quotations')
        ->assertOk()
        ->assertJsonPath('meta.total', 3);
});

test('job orders list is paginated and patient-scoped', function () {
    $user = User::factory()->patient()->create();
    JobOrder::factory()->count(4)->create(['patient_id' => $user->patient->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/optical-orders')
        ->assertOk();

    expect(count($response->json('data')))->toBe(4);
});

test('cross-patient resources return not found', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();

    $prescription = Prescription::factory()->create(['patient_id' => $userB->patient->id]);
    $quotation = Quotation::factory()->create(['patient_id' => $userB->patient->id]);
    $jobOrder = JobOrder::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA);

    $this->getJson("/api/v1/prescriptions/{$prescription->id}")->assertNotFound();
    $this->getJson("/api/v1/quotations/{$quotation->id}")->assertNotFound();
    $this->getJson("/api/v1/optical-orders/{$jobOrder->id}")->assertNotFound();
});
