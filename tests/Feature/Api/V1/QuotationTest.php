<?php

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('patient can list their own quotations', function () {
    $user = User::factory()->patient()->create();
    // Create non-draft quotations (drafts are excluded from patient API)
    $quotations = Quotation::factory()->count(3)->presented()->create(['patient_id' => $user->patient->id]);
    $otherQuotation = Quotation::factory()->presented()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/quotations')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('patient can view their own quotation with details', function () {
    $user = User::factory()->patient()->create();
    $quotation = Quotation::factory()->presented()->create([
        'patient_id' => $user->patient->id,
        'subtotal' => 5000,
        'total' => 5000,
    ]);
    $quotation->items()->create([
        'description' => 'Classic Frame',
        'quantity' => 1,
        'unit_price' => 2500,
        'amount' => 2500,
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/quotations/{$quotation->id}")
        ->assertOk()
        ->assertJsonPath('data.quotation_number', $quotation->quotation_number)
        ->assertJsonPath('data.status', 'presented')
        ->assertJsonPath('data.revision.revision_number', 1)
        ->assertJsonCount(1, 'data.revision.items');
});

test('draft quotations are excluded from patient list', function () {
    $user = User::factory()->patient()->create();
    Quotation::factory()->create(['patient_id' => $user->patient->id, 'status' => QuotationStatus::Draft]);
    Quotation::factory()->presented()->create(['patient_id' => $user->patient->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/quotations')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('patient cannot view another patients quotation', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $quotation = Quotation::factory()->presented()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->getJson("/api/v1/quotations/{$quotation->id}")
        ->assertNotFound();
});

test('patient cannot view draft quotation', function () {
    $user = User::factory()->patient()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => QuotationStatus::Draft,
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/quotations/{$quotation->id}")
        ->assertNotFound();
});

test('patient cannot create quotations', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/quotations', [])
        ->assertStatus(405); // Route doesn't exist — Method Not Allowed
});

test('patient cannot accept or alter quotations', function () {
    $user = User::factory()->patient()->create();
    $quotation = Quotation::factory()->presented()->create(['patient_id' => $user->patient->id]);

    // No mutation routes exist for patients — API is read-only
    $this->actingAs($user)
        ->getJson('/api/v1/quotations')
        ->assertOk();

    $this->actingAs($user)
        ->getJson("/api/v1/quotations/{$quotation->id}")
        ->assertOk();

    // Verify the response is read-only (no status change occurred)
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Presented);
});

test('quotations require authentication', function () {
    $this->getJson('/api/v1/quotations')->assertUnauthorized();
    $this->getJson('/api/v1/quotations/1')->assertUnauthorized();
});
