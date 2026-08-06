<?php

use App\Actions\Reservations\CreateFrameReservation;
use App\Enums\ReservationStatus;
use App\Models\Appointment;
use App\Models\Brand;
use App\Models\FrameReservation;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->brand = Brand::factory()->create();
    $this->action = app(CreateFrameReservation::class);
});

function createEligibleAppointment(Patient $patient): Appointment
{
    return Appointment::factory()->create([
        'patient_id' => $patient->id,
        'scheduled_at' => now()->addDay(),
        'duration_minutes' => 30,
    ]);
}

function createActiveFrameVariant(): ProductVariant
{
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $brand->id,
    ]);

    return ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => true,
        'stock_quantity' => 5,
    ]);
}

test('valid scheduled appointment creates one reservation with items', function () {
    $user = User::factory()->patient()->create();
    $appointment = createEligibleAppointment($user->patient);
    $variant1 = createActiveFrameVariant();
    $variant2 = createActiveFrameVariant();

    $reservation = $this->action->handle(
        patient: $user->patient,
        appointment: $appointment,
        items: [
            ['product_variant_id' => $variant1->id],
            ['product_variant_id' => $variant2->id],
        ],
    );

    expect($reservation->appointment_id)->toBe($appointment->id)
        ->and($reservation->patient_id)->toBe($user->patient->id)
        ->and($reservation->status)->toBe(ReservationStatus::Requested)
        ->and($reservation->items)->toHaveCount(2);
});

test('past appointment is rejected', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'scheduled_at' => now()->subDay(),
        'duration_minutes' => 30,
    ]);
    $variant = createActiveFrameVariant();

    $this->action->handle(
        patient: $user->patient,
        appointment: $appointment,
        items: [['product_variant_id' => $variant->id]],
    );
})->throws(ValidationException::class);

test('cancelled appointment is rejected', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->cancelled()->create([
        'patient_id' => $user->patient->id,
    ]);
    $variant = createActiveFrameVariant();

    $this->action->handle(
        patient: $user->patient,
        appointment: $appointment,
        items: [['product_variant_id' => $variant->id]],
    );
})->throws(ValidationException::class);

test('another patients appointment is rejected', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $appointment = createEligibleAppointment($userB->patient);
    $variant = createActiveFrameVariant();

    $this->action->handle(
        patient: $userA->patient,
        appointment: $appointment,
        items: [['product_variant_id' => $variant->id]],
    );
})->throws(ValidationException::class);

test('a second active reservation for the same appointment is rejected', function () {
    $user = User::factory()->patient()->create();
    $appointment = createEligibleAppointment($user->patient);
    $variant1 = createActiveFrameVariant();
    $variant2 = createActiveFrameVariant();

    // First reservation
    $this->action->handle(
        patient: $user->patient,
        appointment: $appointment,
        items: [['product_variant_id' => $variant1->id]],
    );

    // Second reservation for same appointment
    $this->action->handle(
        patient: $user->patient,
        appointment: $appointment,
        items: [['product_variant_id' => $variant2->id]],
    );
})->throws(ValidationException::class, 'already has a frame reservation');

test('a terminal reservation still blocks a new one for the same appointment', function () {
    // A reservation is a before-the-visit tool: an appointment gets exactly
    // one, ever. Trying on something else in person doesn't create a new
    // reservation — it just becomes a line item on the eventual sale.
    $user = User::factory()->patient()->create();
    $appointment = createEligibleAppointment($user->patient);
    $variant1 = createActiveFrameVariant();
    $variant2 = createActiveFrameVariant();

    $first = $this->action->handle(
        patient: $user->patient,
        appointment: $appointment,
        items: [['product_variant_id' => $variant1->id]],
    );
    $first->update(['status' => ReservationStatus::Cancelled]);

    $this->action->handle(
        patient: $user->patient,
        appointment: $appointment,
        items: [['product_variant_id' => $variant2->id]],
    );
})->throws(ValidationException::class, 'already has a frame reservation');

test('duplicate variant within reservation is rejected', function () {
    $user = User::factory()->patient()->create();
    $appointment = createEligibleAppointment($user->patient);
    $variant = createActiveFrameVariant();

    $this->action->handle(
        patient: $user->patient,
        appointment: $appointment,
        items: [
            ['product_variant_id' => $variant->id],
            ['product_variant_id' => $variant->id],
        ],
    );
})->throws(ValidationException::class);

test('inactive variant is rejected', function () {
    $user = User::factory()->patient()->create();
    $appointment = createEligibleAppointment($user->patient);
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $brand->id,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => false,
    ]);

    $this->action->handle(
        patient: $user->patient,
        appointment: $appointment,
        items: [['product_variant_id' => $variant->id]],
    );
})->throws(ValidationException::class);

test('non-frame variant is rejected', function () {
    $user = User::factory()->patient()->create();
    $appointment = createEligibleAppointment($user->patient);
    $brand = Brand::factory()->create();
    $product = Product::factory()->create([
        'product_type' => 'lens',
        'is_active' => true,
        'brand_id' => $brand->id,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
    ]);

    $this->action->handle(
        patient: $user->patient,
        appointment: $appointment,
        items: [['product_variant_id' => $variant->id]],
    );
})->throws(ValidationException::class);

test('creation and item persistence are atomic', function () {
    $user = User::factory()->patient()->create();
    $appointment = createEligibleAppointment($user->patient);
    $variant = createActiveFrameVariant();
    $invalidVariantId = 99999;

    try {
        $this->action->handle(
            patient: $user->patient,
            appointment: $appointment,
            items: [
                ['product_variant_id' => $variant->id],
                ['product_variant_id' => $invalidVariantId],
            ],
        );
    } catch (ValidationException $e) {
        // Expected
    }

    // No reservation should exist — transaction rolled back
    expect(FrameReservation::count())->toBe(0);
});
