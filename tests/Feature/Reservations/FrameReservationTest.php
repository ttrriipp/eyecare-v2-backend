<?php

use App\Enums\ReservationStatus;
use App\Models\Appointment;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('reservation belongs to a patient', function () {
    $appointment = Appointment::factory()->create();
    $reservation = FrameReservation::factory()->forAppointment($appointment)->create();

    expect($reservation->patient->id)->toBe($appointment->patient_id);
});

test('reservation always has an appointment', function () {
    $reservation = FrameReservation::factory()->create();

    expect($reservation->appointment_id)->not->toBeNull()
        ->and($reservation->appointment)->not->toBeNull();
});

test('appointment cannot be hard-deleted while reservation exists', function () {
    $appointment = Appointment::factory()->create();
    FrameReservation::factory()->forAppointment($appointment)->create();

    $appointment->delete();

    expect($appointment->trashed())->toBeTrue()
        ->and($appointment->frameReservations()->count())->toBe(1);
});

test('reservation has items referencing frame variants', function () {
    $reservation = FrameReservation::factory()->create();
    $variant = ProductVariant::factory()->create();

    $item = FrameReservationItem::factory()->create([
        'frame_reservation_id' => $reservation->id,
        'product_variant_id' => $variant->id,
    ]);

    expect($item->variant->id)->toBe($variant->id)
        ->and($item->reservation->id)->toBe($reservation->id);
});

test('reservation can have multiple items', function () {
    $reservation = FrameReservation::factory()->create();
    $variants = ProductVariant::factory()->count(3)->create();

    foreach ($variants as $variant) {
        FrameReservationItem::factory()->create([
            'frame_reservation_id' => $reservation->id,
            'product_variant_id' => $variant->id,
        ]);
    }

    expect($reservation->items()->count())->toBe(3);
});

test('reservation defaults to requested status', function () {
    $reservation = FrameReservation::factory()->create();

    expect($reservation->status)->toBe(ReservationStatus::Requested);
});

test('reservation status can be requested prepared cancelled', function () {
    $requested = FrameReservation::factory()->create();
    $prepared = FrameReservation::factory()->prepared()->create();
    $cancelled = FrameReservation::factory()->cancelled()->create();

    expect($requested->status)->toBe(ReservationStatus::Requested)
        ->and($prepared->status)->toBe(ReservationStatus::Prepared)
        ->and($cancelled->status)->toBe(ReservationStatus::Cancelled);
});

test('reservation status types are constrained', function () {
    expect(ReservationStatus::cases())->toContain(
        ReservationStatus::Requested,
        ReservationStatus::Prepared,
        ReservationStatus::TriedOn,
        ReservationStatus::Converted,
        ReservationStatus::Released,
        ReservationStatus::Cancelled,
    );
});

test('reservation factory forAppointment sets patient from appointment', function () {
    $appointment = Appointment::factory()->create();
    $reservation = FrameReservation::factory()->forAppointment($appointment)->create();

    expect($reservation->patient_id)->toBe($appointment->patient_id)
        ->and($reservation->appointment_id)->toBe($appointment->id);
});
