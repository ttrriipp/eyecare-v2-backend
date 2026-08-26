<?php

use App\Actions\SavedFrames\ConvertFrameReservation;
use App\Models\Appointment;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\InventoryMovement;
use App\Models\Patient;
use App\Models\ProductVariant;
use App\Models\SavedFrame;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('converts linked reservation with requested items into saved frames', function () {
    $user = User::factory()->create();
    $patient = Patient::factory()->create(['user_id' => $user->id]);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $variant1 = ProductVariant::factory()->create(['stock_quantity' => 5]);
    $variant2 = ProductVariant::factory()->create(['stock_quantity' => 3]);

    $reservation = FrameReservation::factory()
        ->forAppointment($appointment)
        ->create(['patient_id' => $patient->id]);

    FrameReservationItem::factory()->create([
        'frame_reservation_id' => $reservation->id,
        'product_variant_id' => $variant1->id,
    ]);
    FrameReservationItem::factory()->create([
        'frame_reservation_id' => $reservation->id,
        'product_variant_id' => $variant2->id,
    ]);

    app(ConvertFrameReservation::class)->handle($reservation);

    expect(SavedFrame::query()->where('user_id', $user->id)->count())->toBe(2);
    expect($reservation->exists())->toBeFalse();
});

test('converts linked reservation with accepted items and releases stock', function () {
    $user = User::factory()->create();
    $patient = Patient::factory()->create(['user_id' => $user->id]);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 4]);

    $reservation = FrameReservation::factory()
        ->forAppointment($appointment)
        ->accepted()
        ->create(['patient_id' => $patient->id]);

    FrameReservationItem::factory()->create([
        'frame_reservation_id' => $reservation->id,
        'product_variant_id' => $variant->id,
    ]);

    $movementCountBefore = InventoryMovement::query()->count();

    app(ConvertFrameReservation::class)->handle($reservation);

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(5);
    expect(InventoryMovement::query()->count())->toBe($movementCountBefore + 1);
    expect(SavedFrame::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('unlinked reservation releases stock but creates no saved frames', function () {
    $patient = Patient::factory()->create(['user_id' => null]);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 4]);

    $reservation = FrameReservation::factory()
        ->forAppointment($appointment)
        ->accepted()
        ->create(['patient_id' => $patient->id]);

    FrameReservationItem::factory()->create([
        'frame_reservation_id' => $reservation->id,
        'product_variant_id' => $variant->id,
    ]);

    app(ConvertFrameReservation::class)->handle($reservation);

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(5);
    expect(SavedFrame::query()->count())->toBe(0);
});

test('requested reservation releases no stock', function () {
    $user = User::factory()->create();
    $patient = Patient::factory()->create(['user_id' => $user->id]);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 5]);

    $reservation = FrameReservation::factory()
        ->forAppointment($appointment)
        ->create(['patient_id' => $patient->id]);

    FrameReservationItem::factory()->create([
        'frame_reservation_id' => $reservation->id,
        'product_variant_id' => $variant->id,
    ]);

    $movementCountBefore = InventoryMovement::query()->count();

    app(ConvertFrameReservation::class)->handle($reservation);

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(5);
    expect(InventoryMovement::query()->count())->toBe($movementCountBefore);
});

test('conversion is idempotent - retry after completion does nothing', function () {
    $user = User::factory()->create();
    $patient = Patient::factory()->create(['user_id' => $user->id]);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 5]);

    $reservation = FrameReservation::factory()
        ->forAppointment($appointment)
        ->create(['patient_id' => $patient->id]);

    FrameReservationItem::factory()->create([
        'frame_reservation_id' => $reservation->id,
        'product_variant_id' => $variant->id,
    ]);

    app(ConvertFrameReservation::class)->handle($reservation);

    expect(SavedFrame::query()->count())->toBe(1);
    expect($reservation->exists())->toBeFalse();
});

test('duplicate account/variant choices collapse through unique constraint', function () {
    $user = User::factory()->create();
    $patient = Patient::factory()->create(['user_id' => $user->id]);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 5]);

    // Create a saved frame first
    SavedFrame::factory()->forAccount($user)->forVariant($variant)->create([
        'created_at' => now()->subDay(),
    ]);

    $reservation = FrameReservation::factory()
        ->forAppointment($appointment)
        ->create(['patient_id' => $patient->id]);

    FrameReservationItem::factory()->create([
        'frame_reservation_id' => $reservation->id,
        'product_variant_id' => $variant->id,
    ]);

    app(ConvertFrameReservation::class)->handle($reservation);

    expect(SavedFrame::query()->where('user_id', $user->id)->count())->toBe(1);
});
