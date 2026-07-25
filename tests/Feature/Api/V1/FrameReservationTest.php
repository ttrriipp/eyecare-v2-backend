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
        ->assertJsonPath('data.patient_id', $user->patient->id);

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
