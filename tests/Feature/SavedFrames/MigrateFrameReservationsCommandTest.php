<?php

use App\Models\Appointment;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
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

test('dry-run reports effects without making changes', function () {
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

    $this->artisan('saved-frames:migrate-reservations --dry-run --no-interaction')
        ->assertSuccessful();

    expect(FrameReservation::query()->count())->toBe(1);
    expect(SavedFrame::query()->count())->toBe(0);
});

test('execute converts all reservations', function () {
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

    $this->artisan('saved-frames:migrate-reservations --execute --no-interaction')
        ->assertSuccessful();

    expect(FrameReservation::query()->count())->toBe(0);
    expect(SavedFrame::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('execute is idempotent - rerun on empty does nothing', function () {
    $this->artisan('saved-frames:migrate-reservations --execute --no-interaction')
        ->assertSuccessful();

    expect(FrameReservation::query()->count())->toBe(0);
    expect(SavedFrame::query()->count())->toBe(0);
});

test('execute releases held stock', function () {
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

    $this->artisan('saved-frames:migrate-reservations --execute --no-interaction')
        ->assertSuccessful();

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(5);
});
