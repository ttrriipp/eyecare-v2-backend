<?php

use App\Models\Invoice;
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

test('quotations list is paginated and patient-scoped', function () {
    $user = User::factory()->patient()->create();
    Quotation::factory()->count(3)->create(['patient_id' => $user->patient->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/quotations')
        ->assertOk()
        ->assertJsonPath('meta.total', 3);
});

test('job orders list is paginated and patient-scoped', function () {
    $user = User::factory()->patient()->create();
    JobOrder::factory()->count(4)->create(['patient_id' => $user->patient->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/job-orders')
        ->assertOk();

    expect(count($response->json('data')))->toBe(4);
});

test('invoices list is paginated and patient-scoped', function () {
    $user = User::factory()->patient()->create();
    Invoice::factory()->count(2)->create(['patient_id' => $user->patient->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/invoices')
        ->assertOk();

    expect(count($response->json('data')))->toBe(2);
});

test('cross-patient resources return not found', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();

    $prescription = Prescription::factory()->create(['patient_id' => $userB->patient->id]);
    $quotation = Quotation::factory()->create(['patient_id' => $userB->patient->id]);
    $jobOrder = JobOrder::factory()->create(['patient_id' => $userB->patient->id]);
    $invoice = Invoice::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA);

    $this->getJson("/api/v1/prescriptions/{$prescription->id}")->assertNotFound();
    $this->getJson("/api/v1/quotations/{$quotation->id}")->assertNotFound();
    $this->getJson("/api/v1/job-orders/{$jobOrder->id}")->assertNotFound();
    $this->getJson("/api/v1/invoices/{$invoice->id}")->assertNotFound();
});
