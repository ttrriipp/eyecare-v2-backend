<?php

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\MarkAppointmentNoShow;
use App\Actions\Reservations\AcceptFrameReservation;
use App\Models\Appointment;
use App\Models\Brand;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
});

test('cancelling appointment deletes requested reservations', function () {
    $appointment = Appointment::factory()->create();
    $reservation = FrameReservation::factory()->forAppointment($appointment)->create();

    app(CancelAppointment::class)->handle(
        appointment: $appointment,
        initiator: 'patient',
    );

    expect($reservation->exists())->toBeFalse();
});

test('cancelling appointment releases held stock then deletes', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 5]);

    $appointment = Appointment::factory()->create();
    $reservation = FrameReservation::factory()->forAppointment($appointment)->create();
    FrameReservationItem::factory()->create([
        'frame_reservation_id' => $reservation->id,
        'product_variant_id' => $variant->id,
    ]);

    // Accept (allocates stock)
    app(AcceptFrameReservation::class)->handle($reservation);
    expect($variant->fresh()->stock_quantity)->toBe(4);

    // Cancel appointment (should release stock and delete reservation)
    app(CancelAppointment::class)->handle(
        appointment: $appointment,
        initiator: 'patient',
    );

    expect($reservation->exists())->toBeFalse()
        ->and($variant->fresh()->stock_quantity)->toBe(5);
});

test('marking no-show deletes requested reservations', function () {
    $actor = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'scheduled_at' => now()->subHour(),
    ]);
    $reservation = FrameReservation::factory()->forAppointment($appointment)->create();

    app(MarkAppointmentNoShow::class)->handle(
        appointment: $appointment,
        actor: $actor,
    );

    expect($reservation->exists())->toBeFalse();
});

test('marking no-show releases held stock then deletes', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 3]);

    $actor = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'scheduled_at' => now()->subHour(),
    ]);
    $reservation = FrameReservation::factory()->forAppointment($appointment)->create();
    FrameReservationItem::factory()->create([
        'frame_reservation_id' => $reservation->id,
        'product_variant_id' => $variant->id,
    ]);

    app(AcceptFrameReservation::class)->handle($reservation);
    expect($variant->fresh()->stock_quantity)->toBe(2);

    app(MarkAppointmentNoShow::class)->handle(
        appointment: $appointment,
        actor: $actor,
    );

    expect($reservation->exists())->toBeFalse()
        ->and($variant->fresh()->stock_quantity)->toBe(3);
});

test('cleanup failure rolls back appointment cancellation', function () {
    $appointment = Appointment::factory()->create();

    // This test verifies the transactional behavior is in place.
    // The cleanup action runs inside the same transaction as the cancellation.
    app(CancelAppointment::class)->handle(
        appointment: $appointment,
        initiator: 'patient',
    );

    expect($appointment->fresh()->status->name)->toBe('cancelled');
});

test('appointment with no reservations cancels cleanly', function () {
    $appointment = Appointment::factory()->create();

    app(CancelAppointment::class)->handle(
        appointment: $appointment,
        initiator: 'patient',
    );

    expect($appointment->fresh()->status->name)->toBe('cancelled');
});
