<?php

use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated customers can list their own prescription history', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();

    $ownPrescriptions = Prescription::factory()->count(2)->create([
        'customer_id' => $customer->id,
    ]);

    Prescription::factory()->create([
        'customer_id' => $otherCustomer->id,
    ]);

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson('/api/prescriptions');

    $response->assertSuccessful();

    $prescriptionIds = collect($response->json('data'))->pluck('id')->all();

    expect($prescriptionIds)
        ->toEqualCanonicalizing($ownPrescriptions->pluck('id')->all())
        ->and($prescriptionIds)->toHaveCount(2);
});

test('customers can view their own prescription', function () {
    $customer = User::factory()->customer()->create();
    $prescription = Prescription::factory()->create([
        'customer_id' => $customer->id,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/prescriptions/{$prescription->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $prescription->id)
        ->assertJsonPath('data.od_sphere', (string) $prescription->od_sphere)
        ->assertJsonPath('data.pd', (string) $prescription->pd);
});

test('customers cannot view another customers prescription', function () {
    $customer = User::factory()->customer()->create();
    $otherPrescription = Prescription::factory()->create();

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/prescriptions/{$otherPrescription->id}")
        ->assertNotFound();
});

test('prescription endpoints require authentication', function () {
    $prescription = Prescription::factory()->create();

    $this->getJson('/api/prescriptions')->assertUnauthorized();
    $this->getJson("/api/prescriptions/{$prescription->id}")->assertUnauthorized();
});
