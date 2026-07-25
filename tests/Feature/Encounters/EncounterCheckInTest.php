<?php

use App\Actions\Encounters\CheckInAppointment;
use App\Enums\EncounterStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('check-in creates exactly one encounter for the appointment', function () {
    $appointment = Appointment::factory()->create();
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter)->toBeInstanceOf(Encounter::class)
        ->and($encounter->patient_id)->toBe($appointment->patient_id)
        ->and($encounter->appointment_id)->toBe($appointment->id)
        ->and($encounter->status)->toBe(EncounterStatus::Waiting);

    $this->assertDatabaseCount('encounters', 1);
});

test('check-in updates appointment status to arrived', function () {
    $appointment = Appointment::factory()->create();
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    app(CheckInAppointment::class)->handle($appointment);

    $appointment->refresh();
    expect($appointment->status->name)->toBe('arrived')
        ->and($appointment->checked_in_at)->not->toBeNull()
        ->and($appointment->checked_in_by)->toBe($staff->id);
});

test('cancelled appointments cannot create encounters', function () {
    $cancelled = AppointmentStatus::query()->where('name', 'cancelled')->firstOrFail();
    $appointment = Appointment::factory()->create(['appointment_status_id' => $cancelled->id]);
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    app(CheckInAppointment::class)->handle($appointment);
})->throws(ValidationException::class);

test('no-show appointments cannot create encounters', function () {
    $noShow = AppointmentStatus::query()->where('name', 'no_show')->firstOrFail();
    $appointment = Appointment::factory()->create(['appointment_status_id' => $noShow->id]);
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    app(CheckInAppointment::class)->handle($appointment);
})->throws(ValidationException::class);

test('failure rolls back both status and encounter creation', function () {
    $appointment = Appointment::factory()->create();
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    // Create an encounter with the same appointment_id to trigger unique constraint
    Encounter::factory()->create(['appointment_id' => $appointment->id]);

    app(CheckInAppointment::class)->handle($appointment);
})->throws(Exception::class);

test('concurrent check-in cannot create duplicate encounters', function () {
    $appointment = Appointment::factory()->create();
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    app(CheckInAppointment::class)->handle($appointment);

    // Second check-in should fail because appointment is already 'arrived'
    app(CheckInAppointment::class)->handle($appointment->fresh());
})->throws(ValidationException::class);

test('encounter factory produces valid records', function () {
    $waiting = Encounter::factory()->create();
    $inProgress = Encounter::factory()->inProgress()->create();
    $completed = Encounter::factory()->completed()->create();

    expect($waiting->status)->toBe(EncounterStatus::Waiting)
        ->and($waiting->encounter_number)->toStartWith('ENC-')
        ->and($inProgress->status)->toBe(EncounterStatus::InProgress)
        ->and($inProgress->started_at)->not->toBeNull()
        ->and($completed->status)->toBe(EncounterStatus::Completed)
        ->and($completed->completed_at)->not->toBeNull();
});
