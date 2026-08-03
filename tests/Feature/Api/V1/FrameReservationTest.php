<?php

use App\Enums\ReservationStatus;
use App\Models\Appointment;
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

test('patient can create a frame reservation with appointment', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'scheduled_at' => now()->addDay(),
        'duration_minutes' => 30,
    ]);
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
            'appointment_id' => $appointment->id,
            'items' => [['product_variant_id' => $variant->id]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'requested')
        ->assertJsonPath('data.appointment.id', $appointment->id)
        ->assertJsonPath('data.appointment.appointment_number', $appointment->appointment_number)
        ->assertJsonCount(1, 'data.items');

    $this->assertDatabaseHas('frame_reservations', [
        'patient_id' => $user->patient->id,
        'appointment_id' => $appointment->id,
        'status' => 'requested',
    ]);
});

test('appointment_id is required', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/frame-reservations', [
            'items' => [['product_variant_id' => 1]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment_id']);
});

test('another patients appointment returns 404', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $userB->patient->id,
        'scheduled_at' => now()->addDay(),
    ]);
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => true,
    ]);

    $this->actingAs($userA)
        ->postJson('/api/v1/frame-reservations', [
            'appointment_id' => $appointment->id,
            'items' => [['product_variant_id' => $variant->id]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment_id']);
});

test('cancelled appointment is rejected', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->cancelled()->create([
        'patient_id' => $user->patient->id,
    ]);
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/frame-reservations', [
            'appointment_id' => $appointment->id,
            'items' => [['product_variant_id' => $variant->id]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment_id']);
});

test('checked-in appointment is rejected through patient endpoint', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->checkedIn()->create([
        'patient_id' => $user->patient->id,
    ]);
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/frame-reservations', [
            'appointment_id' => $appointment->id,
            'items' => [['product_variant_id' => $variant->id]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment_id']);
});

test('duplicate active reservation is rejected', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'scheduled_at' => now()->addDay(),
        'duration_minutes' => 30,
    ]);
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    $variant1 = ProductVariant::factory()->create(['product_id' => $frame->id, 'is_active' => true]);
    $variant2 = ProductVariant::factory()->create(['product_id' => $frame->id, 'is_active' => true]);

    // First reservation
    $this->actingAs($user)
        ->postJson('/api/v1/frame-reservations', [
            'appointment_id' => $appointment->id,
            'items' => [['product_variant_id' => $variant1->id]],
        ])
        ->assertCreated();

    // Second reservation for same appointment
    $this->actingAs($user)
        ->postJson('/api/v1/frame-reservations', [
            'appointment_id' => $appointment->id,
            'items' => [['product_variant_id' => $variant2->id]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment_id']);
});

test('responses contain appointment display context', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'scheduled_at' => now()->addDay(),
        'duration_minutes' => 30,
    ]);
    $reservation = FrameReservation::factory()->forAppointment($appointment)->create();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/frame-reservations')
        ->assertOk();

    $response->assertJsonPath('data.0.appointment.id', $appointment->id);
    $response->assertJsonPath('data.0.appointment.appointment_number', $appointment->appointment_number);
    $response->assertJsonPath('data.0.appointment.scheduled_at', $appointment->scheduled_at->toIso8601String());
    $response->assertJsonPath('data.0.appointment.duration_minutes', $appointment->duration_minutes);
});

test('patient can list their own reservations', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create(['patient_id' => $user->patient->id]);
    FrameReservation::factory()->count(2)->forAppointment($appointment)->create();
    FrameReservation::factory()->create(); // other patient

    $this->actingAs($user)
        ->getJson('/api/v1/frame-reservations')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('patient can cancel their own requested reservation', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create(['patient_id' => $user->patient->id]);
    $reservation = FrameReservation::factory()->forAppointment($appointment)->create([
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
    $appointment = Appointment::factory()->create(['patient_id' => $userB->patient->id]);
    $reservation = FrameReservation::factory()->forAppointment($appointment)->create();

    $this->actingAs($userA)
        ->postJson("/api/v1/frame-reservations/{$reservation->id}/cancel")
        ->assertForbidden();
});

test('reservation requires authentication', function () {
    $this->getJson('/api/v1/frame-reservations')->assertUnauthorized();
    $this->postJson('/api/v1/frame-reservations', [])->assertUnauthorized();
});

test('unlinked patient account cannot create a frame reservation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/frame-reservations', [])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ACTIVE_PATIENT_LINK_REQUIRED');
});

test('reservation response does not contain internal commercial or inventory fields', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create(['patient_id' => $user->patient->id]);
    FrameReservation::factory()->forAppointment($appointment)->create([
        'status' => ReservationStatus::Requested,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/frame-reservations')
        ->assertOk();

    // Reservation-level: no patient_id, no staff_notes, no deleted_at
    $response->assertJsonMissing(['patient_id']);
    $response->assertJsonMissing(['staff_notes']);
    $response->assertJsonMissing(['deleted_at']);
});
