<?php

use App\Actions\Reservations\PrepareFrameReservation;
use App\Actions\Reservations\ReleaseFrameReservation;
use App\Enums\ReservationStatus;
use App\Models\Brand;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('preparation locks records and creates one allocation per frame', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant1 = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 5]);
    $variant2 = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 3]);

    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    FrameReservationItem::factory()->create(['frame_reservation_id' => $reservation->id, 'product_variant_id' => $variant1->id]);
    FrameReservationItem::factory()->create(['frame_reservation_id' => $reservation->id, 'product_variant_id' => $variant2->id]);

    $user = User::factory()->staff()->create();
    $this->actingAs($user);

    app(PrepareFrameReservation::class)->handle($reservation);

    expect($variant1->fresh()->stock_quantity)->toBe(4)
        ->and($variant2->fresh()->stock_quantity)->toBe(2)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Prepared);

    // One movement per item
    expect(InventoryMovement::where('reservation_id', $reservation->id)->count())->toBe(2);
});

test('release restores stock once', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 4]);

    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Prepared]);
    FrameReservationItem::factory()->create(['frame_reservation_id' => $reservation->id, 'product_variant_id' => $variant->id]);

    $user = User::factory()->staff()->create();
    $this->actingAs($user);

    app(ReleaseFrameReservation::class)->handle($reservation);

    expect($variant->fresh()->stock_quantity)->toBe(5)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Released);

    // Double release is idempotent
    app(ReleaseFrameReservation::class)->handle($reservation->fresh());
    expect($variant->fresh()->stock_quantity)->toBe(5);
});

test('expiry command releases expired reservations', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 3]);

    $expired = FrameReservation::factory()->create([
        'status' => ReservationStatus::Prepared,
        'expires_at' => Carbon::now()->subHour(),
    ]);
    FrameReservationItem::factory()->create(['frame_reservation_id' => $expired->id, 'product_variant_id' => $variant->id]);

    $this->artisan('reservations:expire')
        ->assertExitCode(0);

    expect($variant->fresh()->stock_quantity)->toBe(4)
        ->and($expired->fresh()->status)->toBe(ReservationStatus::Released);
});

test('expiry command is idempotent', function () {
    $expired = FrameReservation::factory()->create([
        'status' => ReservationStatus::Prepared,
        'expires_at' => Carbon::now()->subHour(),
    ]);

    $this->artisan('reservations:expire');
    $this->artisan('reservations:expire');

    expect($expired->fresh()->status)->toBe(ReservationStatus::Released);
});
