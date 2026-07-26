<?php

use App\Actions\Encounters\CheckInAppointment;
use App\Enums\EncounterStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Encounter;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
});

test('check-in creates exactly one encounter', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter)->toBeInstanceOf(Encounter::class)
        ->and($encounter->patient_id)->toBe($appointment->patient_id)
        ->and($encounter->appointment_id)->toBe($appointment->id)
        ->and($encounter->status)->toBe(EncounterStatus::Waiting);

    $this->assertDatabaseCount('encounters', 1);
});

test('status-only arrival cannot bypass encounter creation', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    app(CheckInAppointment::class)->handle($appointment);

    // Verify the encounter was created
    $this->assertDatabaseHas('encounters', [
        'appointment_id' => $appointment->id,
        'status' => 'waiting',
    ]);

    // Verify the appointment status was updated
    $appointment->refresh();
    expect($appointment->status->name)->toBe('arrived');
});

test('cancelled appointments cannot create encounters', function () {
    $staff = User::factory()->staff()->create();
    $cancelled = AppointmentStatus::query()->where('name', 'cancelled')->firstOrFail();
    $appointment = Appointment::factory()->create(['appointment_status_id' => $cancelled->id]);

    $this->actingAs($staff);

    app(CheckInAppointment::class)->handle($appointment);
})->throws(ValidationException::class);

test('no-show appointments cannot create encounters', function () {
    $staff = User::factory()->staff()->create();
    $noShow = AppointmentStatus::query()->where('name', 'no_show')->firstOrFail();
    $appointment = Appointment::factory()->create(['appointment_status_id' => $noShow->id]);

    $this->actingAs($staff);

    app(CheckInAppointment::class)->handle($appointment);
})->throws(ValidationException::class);

test('concurrent check-in creates one encounter and one audit event', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    // First check-in succeeds
    app(CheckInAppointment::class)->handle($appointment);

    // Second check-in should fail (appointment already arrived)
    app(CheckInAppointment::class)->handle($appointment->fresh());
})->throws(ValidationException::class);

test('check-in creates an audit event', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    app(CheckInAppointment::class)->handle($appointment);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'appointment.checked_in',
    ]);
});
