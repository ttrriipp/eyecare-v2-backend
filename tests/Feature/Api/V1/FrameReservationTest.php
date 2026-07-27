<?php

use App\Enums\ReservationStatus;
use App\Models\Brand;
use App\Models\FrameReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->brand = Brand::factory()->create();
});

test('patient can create a frame reservation', function () {
    $user = User::factory()->patient()->create();
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => true,
        'stock_quantity' => 5,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/frame-reservations', [
            'items' => [['product_variant_id' => $variant->id]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'requested')
        ->assertJsonCount(1, 'data.items');

    $this->assertDatabaseHas('frame_reservations', [
        'patient_id' => $user->patient->id,
        'status' => 'requested',
    ]);
});

test('patient can list their own reservations', function () {
    $user = User::factory()->patient()->create();
    $myReservations = FrameReservation::factory()->count(2)->create(['patient_id' => $user->patient->id]);
    $otherReservation = FrameReservation::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/frame-reservations')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('patient can cancel their own requested reservation', function () {
    $user = User::factory()->patient()->create();
    $reservation = FrameReservation::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => ReservationStatus::Requested,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/frame-reservations/{$reservation->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('patient cannot cancel another patients reservation', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $reservation = FrameReservation::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->postJson("/api/v1/frame-reservations/{$reservation->id}/cancel")
        ->assertForbidden();
});

test('reservation requires authentication', function () {
    $this->getJson('/api/v1/frame-reservations')->assertUnauthorized();
    $this->postJson('/api/v1/frame-reservations', [])->assertUnauthorized();
});

test('reservation response does not contain internal commercial or inventory fields', function () {
    $user = User::factory()->patient()->create();
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => true,
        'stock_quantity' => 10,
        'cost_price' => 1500.00,
        'low_stock_threshold' => 3,
        'target_stock_level' => 20,
    ]);
    FrameReservation::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => ReservationStatus::Requested,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/frame-reservations')
        ->assertOk();

    // Reservation-level: no patient_id, no staff_notes, no deleted_at
    $response->assertJsonMissing(['patient_id']);
    $response->assertJsonMissing(['staff_notes']);
    $response->assertJsonMissing(['deleted_at']);

    // Variant-level: no cost_price, stock_quantity, low_stock_threshold, target_stock_level
    $response->assertJsonMissing(['cost_price']);
    $response->assertJsonMissing(['stock_quantity']);
    $response->assertJsonMissing(['low_stock_threshold']);
    $response->assertJsonMissing(['target_stock_level']);
    $response->assertJsonMissing(['is_active']);
    $response->assertJsonMissing(['ar_eligible']);
    $response->assertJsonMissing(['ar_asset_reference']);
    $response->assertJsonMissing(['product_id']);
});
