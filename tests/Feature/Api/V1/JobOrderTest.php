<?php

use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('patient can list their own job orders', function () {
    $user = User::factory()->patient()->create();

    // Create job orders directly with the patient's ID
    JobOrder::query()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Queued,
        'total_amount' => 1000,
    ]);
    JobOrder::query()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Queued,
        'total_amount' => 2000,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/optical-orders')
        ->assertOk();

    $json = $response->json();
    // Check both possible response structures
    $count = isset($json['data']) ? (is_array($json['data']) ? count($json['data']) : 0) : 0;
    expect($count)->toBe(2);
});

test('patient can view their own job order with items', function () {
    $user = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'supplier_invoice_number' => 'INTERNAL-SUP-1001',
    ]);
    JobOrderItem::factory()->count(2)->create(['job_order_id' => $jobOrder->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertOk()
        ->assertJsonPath('data.order_number', $jobOrder->job_order_number)
        ->assertJsonMissingPath('data.supplier_invoice_number')
        ->assertJsonCount(2, 'data.items');
});

test('patient cannot view another patients job order', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertNotFound();
});

test('job orders require authentication', function () {
    $this->getJson('/api/v1/optical-orders')->assertUnauthorized();
});
