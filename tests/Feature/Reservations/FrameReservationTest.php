<?php

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

test('reservation defaults to unaccepted', function () {
    $reservation = FrameReservation::factory()->create();

    expect($reservation->accepted_at)->toBeNull()
        ->and($reservation->isHeld())->toBeFalse();
});

test('reservation accepted state sets accepted_at', function () {
    $reservation = FrameReservation::factory()->accepted()->create();

    expect($reservation->accepted_at)->not->toBeNull()
        ->and($reservation->isHeld())->toBeTrue();
});

test('reservation factory forAppointment sets patient from appointment', function () {
    $appointment = Appointment::factory()->create();
    $reservation = FrameReservation::factory()->forAppointment($appointment)->create();

    expect($reservation->patient_id)->toBe($appointment->patient_id)
        ->and($reservation->appointment_id)->toBe($appointment->id);
});
