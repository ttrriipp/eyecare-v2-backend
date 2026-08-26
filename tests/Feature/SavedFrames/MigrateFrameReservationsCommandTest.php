<?php

use App\Models\Appointment;
use App\Models\InventoryMovement;
use App\Models\Patient;
use App\Models\ProductVariant;
use App\Models\SavedFrame;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    createSavedFramesLegacyReservationTables();
});

afterEach(function (): void {
    Schema::dropIfExists('frame_reservation_items');
    Schema::dropIfExists('frame_reservations');
});

test('dry-run reports conversion effects without changing legacy data', function () {
    $user = User::factory()->create();
    $patient = Patient::factory()->create(['user_id' => $user->id]);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 5]);
    $reservationId = createSavedFramesLegacyReservation($patient->id, $appointment->id);
    createSavedFramesLegacyReservationItem($reservationId, $variant->id);

    $this->artisan('saved-frames:migrate-reservations', ['--dry-run' => true])
        ->expectsOutputToContain('Reservations: 1')
        ->expectsOutputToContain('Total items: 1')
        ->expectsOutputToContain('Dry run complete. No changes made.')
        ->assertSuccessful();

    expect(DB::table('frame_reservations')->count())->toBe(1)
        ->and(DB::table('frame_reservation_items')->count())->toBe(1)
        ->and(SavedFrame::query()->count())->toBe(0)
        ->and($variant->refresh()->stock_quantity)->toBe(5);
});

test('execute converts linked requested choices and preserves item creation time', function () {
    $user = User::factory()->create();
    $patient = Patient::factory()->create(['user_id' => $user->id]);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 5]);
    $reservationId = createSavedFramesLegacyReservation($patient->id, $appointment->id);
    $itemCreatedAt = now()->subDays(3)->startOfSecond();
    createSavedFramesLegacyReservationItem($reservationId, $variant->id, $itemCreatedAt->toDateTimeString());

    $this->artisan('saved-frames:migrate-reservations', ['--execute' => true])
        ->expectsOutputToContain('Migration complete. All reservation rows removed.')
        ->assertSuccessful();

    $savedFrame = SavedFrame::query()
        ->where('user_id', $user->id)
        ->where('product_variant_id', $variant->id)
        ->first();

    expect($savedFrame)->not->toBeNull()
        ->and($savedFrame->created_at->equalTo($itemCreatedAt))->toBeTrue()
        ->and(DB::table('frame_reservations')->count())->toBe(0)
        ->and(DB::table('frame_reservation_items')->count())->toBe(0)
        ->and($variant->refresh()->stock_quantity)->toBe(5);
});

test('execute releases each accepted item exactly once and records its reservation provenance', function () {
    $user = User::factory()->create();
    $patient = Patient::factory()->create(['user_id' => $user->id]);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 4]);
    $reservationId = createSavedFramesLegacyReservation($patient->id, $appointment->id, now()->toDateTimeString());
    createSavedFramesLegacyReservationItem($reservationId, $variant->id);

    $movementCount = InventoryMovement::query()->count();

    $this->artisan('saved-frames:migrate-reservations', ['--execute' => true])
        ->assertSuccessful();

    $movement = InventoryMovement::query()
        ->where('reservation_id', $reservationId)
        ->first();

    expect($variant->refresh()->stock_quantity)->toBe(5)
        ->and(InventoryMovement::query()->count())->toBe($movementCount + 1)
        ->and($movement)->not->toBeNull()
        ->and($movement->quantity_change)->toBe(1)
        ->and($movement->previous_stock)->toBe(4)
        ->and($movement->new_stock)->toBe(5)
        ->and($movement->movementType->name)->toBe('reservation_release')
        ->and(SavedFrame::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('execute releases held stock for an unlinked patient without assigning ownership', function () {
    $patient = Patient::factory()->create(['user_id' => null]);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 4]);
    $reservationId = createSavedFramesLegacyReservation($patient->id, $appointment->id, now()->toDateTimeString());
    createSavedFramesLegacyReservationItem($reservationId, $variant->id);

    $this->artisan('saved-frames:migrate-reservations', ['--execute' => true])
        ->assertSuccessful();

    expect($variant->refresh()->stock_quantity)->toBe(5)
        ->and(SavedFrame::query()->count())->toBe(0)
        ->and(DB::table('frame_reservations')->count())->toBe(0);
});

test('execute retains the earliest timestamp when a choice already exists', function () {
    $user = User::factory()->create();
    $patient = Patient::factory()->create(['user_id' => $user->id]);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $variant = ProductVariant::factory()->create();
    $existingCreatedAt = now()->subDay()->startOfSecond();
    $itemCreatedAt = now()->subDays(3)->startOfSecond();
    SavedFrame::factory()->forAccount($user)->forVariant($variant)->create([
        'created_at' => $existingCreatedAt,
        'updated_at' => $existingCreatedAt,
    ]);
    $reservationId = createSavedFramesLegacyReservation($patient->id, $appointment->id);
    createSavedFramesLegacyReservationItem($reservationId, $variant->id, $itemCreatedAt->toDateTimeString());

    $this->artisan('saved-frames:migrate-reservations', ['--execute' => true])
        ->assertSuccessful();

    expect(SavedFrame::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(SavedFrame::query()->where('user_id', $user->id)->first()->created_at->equalTo($itemCreatedAt))->toBeTrue();
});

test('command requires exactly one execution mode', function () {
    $this->artisan('saved-frames:migrate-reservations')
        ->expectsOutputToContain('Please specify either --dry-run or --execute.')
        ->assertFailed();

    $this->artisan('saved-frames:migrate-reservations', [
        '--dry-run' => true,
        '--execute' => true,
    ])
        ->expectsOutputToContain('Choose only one of --dry-run or --execute.')
        ->assertFailed();
});

function createSavedFramesLegacyReservationTables(): void
{
    Schema::create('frame_reservations', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('patient_id');
        $table->unsignedBigInteger('appointment_id')->nullable();
        $table->timestamp('accepted_at')->nullable();
        $table->text('staff_notes')->nullable();
        $table->timestamps();
        $table->unique('appointment_id');
    });

    Schema::create('frame_reservation_items', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('frame_reservation_id');
        $table->unsignedBigInteger('product_variant_id');
        $table->timestamps();
        $table->index(
            ['frame_reservation_id', 'product_variant_id'],
            'saved_frames_legacy_reservation_item_variant_index',
        );
    });
}

function createSavedFramesLegacyReservation(
    int $patientId,
    int $appointmentId,
    ?string $acceptedAt = null,
    ?string $createdAt = null,
): int {
    $timestamp = $createdAt ?? now()->toDateTimeString();

    return (int) DB::table('frame_reservations')->insertGetId([
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'accepted_at' => $acceptedAt,
        'staff_notes' => null,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
}

function createSavedFramesLegacyReservationItem(
    int $reservationId,
    int $variantId,
    ?string $createdAt = null,
): void {
    $timestamp = $createdAt ?? now()->toDateTimeString();

    DB::table('frame_reservation_items')->insert([
        'frame_reservation_id' => $reservationId,
        'product_variant_id' => $variantId,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
}
