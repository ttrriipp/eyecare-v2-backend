<?php

use App\Models\AccessoryOrderRequest;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// --- Account-Only Routes (no link required) ---

test('unlinked account can access me endpoint', function () {
    // Create a patient-role user without a linked Patient record
    $user = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk();
});

test('unlinked account can logout', function () {
    $user = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/logout')
        ->assertOk();
});

// --- Clinical Routes (link required) ---

test('unlinked account cannot access prescriptions', function () {
    $user = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/prescriptions')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ACTIVE_PATIENT_LINK_REQUIRED');
});

test('unlinked account cannot access appointments', function () {
    $user = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ACTIVE_PATIENT_LINK_REQUIRED');
});

test('unlinked patient account can browse accessories but cannot access accessory ordering surfaces', function () {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', Role::Patient)->firstOrFail());

    $accessory = Product::factory()->accessory()->create();
    $variant = ProductVariant::factory()->for($accessory, 'product')->create([
        'is_active' => true,
        'stock_quantity' => 10,
    ]);
    InventoryLot::factory()->for($variant, 'variant')->create([
        'received_by' => $user->id,
        'quantity_on_hand' => 10,
        'expires_on' => now()->addMonths(6)->toDateString(),
    ]);
    $orderRequest = AccessoryOrderRequest::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/accessories')
        ->assertOk()
        ->assertJsonPath('data.0.id', $accessory->id);

    $this->actingAs($user)
        ->getJson("/api/v1/accessories/{$accessory->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $accessory->id);

    $commerceRoutes = [
        ['GET', '/api/v1/accessory-order-requests', []],
        ['POST', '/api/v1/accessory-order-requests', []],
        ['GET', "/api/v1/accessory-order-requests/{$orderRequest->id}", []],
        ['POST', "/api/v1/accessory-order-requests/{$orderRequest->id}/cancel", []],
    ];

    foreach ($commerceRoutes as [$method, $uri, $payload]) {
        $this->actingAs($user)
            ->json($method, $uri, $payload)
            ->assertForbidden()
            ->assertJson([
                'error' => [
                    'code' => 'ACTIVE_PATIENT_LINK_REQUIRED',
                    'message' => 'An active patient link is required.',
                ],
            ]);
    }
});

test('unlinked account can access its account-owned conversation', function () {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', Role::Patient)->firstOrFail());

    $this->actingAs($user)
        ->getJson('/api/v1/conversation')
        ->assertCreated()
        ->assertJsonPath('data.access_level', 'general_inquiry')
        ->assertJsonPath('data.capabilities.can_upload_attachments', false);
});

test('linked account can access clinical routes', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/prescriptions')
        ->assertOk();
});

test('identity review does not suspend linked clinical access', function (): void {
    $user = User::factory()->patient()->create();
    $user->patient->forceFill([
        'identity_review_required' => true,
        'identity_review_required_at' => now(),
    ])->saveQuietly();

    $this->actingAs($user)
        ->getJson('/api/v1/prescriptions')
        ->assertOk();
});

test('unauthenticated request returns 401', function () {
    $this->getJson('/api/v1/prescriptions')
        ->assertUnauthorized();
});
