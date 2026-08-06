<?php

use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\RelationManagers\FrameReservationItemsRelationManager;
use App\Filament\Resources\FrameReservations\FrameReservationResource;
use App\Models\Appointment;
use App\Models\FrameReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
});

test('staff can reserve frames for a manually-scheduled appointment', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create(['source' => 'manual']);
    $variant = ProductVariant::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionVisible('reserveFrames')
        ->callAction('reserveFrames', [
            'product_variant_ids' => [$variant->id],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified()
        ->assertRedirect();

    $reservation = FrameReservation::query()->where('appointment_id', $appointment->id)->firstOrFail();

    expect($reservation->patient_id)->toBe($appointment->patient_id)
        ->and($reservation->items)->toHaveCount(1)
        ->and($reservation->items->first()->product_variant_id)->toBe($variant->id);
});

test('staff can reserve frames for a walk-in appointment the same as a mobile one', function () {
    $staff = User::factory()->staff()->create();
    $walkIn = Appointment::factory()->create(['source' => 'walk_in']);
    $variant = ProductVariant::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $walkIn->getRouteKey()])
        ->assertActionVisible('reserveFrames')
        ->callAction('reserveFrames', ['product_variant_ids' => [$variant->id]])
        ->assertHasNoActionErrors();

    expect(FrameReservation::query()->where('appointment_id', $walkIn->id)->exists())->toBeTrue();
});

test('reserve frames action is hidden once an active reservation exists', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    FrameReservation::factory()->create([
        'patient_id' => $appointment->patient_id,
        'appointment_id' => $appointment->id,
        'status' => 'requested',
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionHidden('reserveFrames');
});

test('view reservation action is visible once a reservation exists and links to it', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    $reservation = FrameReservation::factory()->create([
        'patient_id' => $appointment->patient_id,
        'appointment_id' => $appointment->id,
        'status' => 'requested',
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionVisible('viewReservation')
        ->assertActionHasUrl('viewReservation', FrameReservationResource::getUrl('edit', ['record' => $reservation]));
});

test('view reservation action is hidden when no reservation exists', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionHidden('viewReservation');
});

test('staff can add another frame from the appointment reservation relation manager', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    $reservation = FrameReservation::factory()->create([
        'patient_id' => $appointment->patient_id,
        'appointment_id' => $appointment->id,
        'status' => 'requested',
    ]);
    $variant = ProductVariant::factory()->create([
        'is_active' => true,
        'product_id' => Product::factory()->create(['product_type' => 'frame', 'is_active' => true])->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(FrameReservationItemsRelationManager::class, [
        'ownerRecord' => $appointment,
        'pageClass' => EditAppointment::class,
    ])
        ->assertActionVisible(TestAction::make('addFrame')->table())
        ->callAction(TestAction::make('addFrame')->table(), ['product_variant_id' => $variant->id])
        ->assertHasNoActionErrors();

    expect($reservation->items()->where('product_variant_id', $variant->id)->exists())->toBeTrue();
});

test('the add frame action is hidden from the appointment relation manager once tried on', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    FrameReservation::factory()->create([
        'patient_id' => $appointment->patient_id,
        'appointment_id' => $appointment->id,
        'status' => 'tried_on',
    ]);

    $this->actingAs($staff);

    Livewire::test(FrameReservationItemsRelationManager::class, [
        'ownerRecord' => $appointment,
        'pageClass' => EditAppointment::class,
    ])
        ->assertActionHidden(TestAction::make('addFrame')->table());
});

test('reserve frames action rejects a non-frame product variant', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    $accessoryVariant = ProductVariant::factory()->create([
        'product_id' => Product::factory()->create(['product_type' => 'accessory'])->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->callAction('reserveFrames', ['product_variant_ids' => [$accessoryVariant->id]]);

    expect(FrameReservation::query()->where('appointment_id', $appointment->id)->exists())->toBeFalse();
});
