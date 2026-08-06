<?php

use App\Actions\Reservations\MarkFrameReservationTriedOn;
use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use Illuminate\Validation\ValidationException;

test('a prepared reservation can be marked as tried on', function () {
    $reservation = FrameReservation::factory()->prepared()->create();

    $updated = app(MarkFrameReservationTriedOn::class)->handle($reservation);

    expect($updated->status)->toBe(ReservationStatus::TriedOn);
});

test('a requested reservation cannot be marked as tried on', function () {
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);

    app(MarkFrameReservationTriedOn::class)->handle($reservation);
})->throws(ValidationException::class, 'Only prepared reservations');

test('an already tried-on reservation cannot be marked as tried on again', function () {
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::TriedOn]);

    app(MarkFrameReservationTriedOn::class)->handle($reservation);
})->throws(ValidationException::class, 'Only prepared reservations');
