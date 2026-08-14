<?php

use App\Actions\Appointments\CreateWalkInAppointment;
use App\Actions\Encounters\CheckInAppointment;
use App\Enums\EncounterStatus;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->optometrist = User::factory()->optometrist()->create();
});

test('check-in copies assigned provider from appointment', function () {
    $appointment = Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->optometrist_id)->toBe($this->optometrist->id);
});

test('check-in with no assigned provider leaves optometrist null', function () {
    $appointment = Appointment::factory()->create([
        'optometrist_id' => null,
    ]);
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->optometrist_id)->toBeNull();
});

test('check-in prefills chief complaint from appointment reason', function () {
    $appointment = Appointment::factory()->create([
        'reason_for_visit' => 'Blurred distance vision',
    ]);
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->chief_complaint)->toBe('Blurred distance vision');
});

test('check-in with no appointment reason leaves chief complaint null', function () {
    $appointment = Appointment::factory()->create([
        'reason_for_visit' => null,
    ]);
    $this->actingAs($this->staff);

    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->chief_complaint)->toBeNull();
});

test('check-in rejects already checked-in appointment', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    app(CheckInAppointment::class)->handle($appointment);

    // Second check-in fails because appointment is now checked_in, not scheduled
    app(CheckInAppointment::class)->handle($appointment->fresh());
})->throws(ValidationException::class);

test('check-in rejects cancelled appointment', function () {
    $appointment = Appointment::factory()->cancelled()->create();
    $this->actingAs($this->staff);

    app(CheckInAppointment::class)->handle($appointment);
})->throws(ValidationException::class);

test('check-in rejects no-show appointment', function () {
    $appointment = Appointment::factory()->noShow()->create();
    $this->actingAs($this->staff);

    app(CheckInAppointment::class)->handle($appointment);
})->throws(ValidationException::class);

test('check-in creates audit event', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs($this->staff);

    app(CheckInAppointment::class)->handle($appointment);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'appointment.checked_in',
    ]);
});

test('walk-in creates encounter with null intake', function () {
    $patient = Patient::factory()->create();
    $appointmentType = AppointmentType::query()->first();
    $this->actingAs($this->staff);

    $appointment = app(CreateWalkInAppointment::class)->handle(
        patient: $patient,
        appointmentType: $appointmentType,
        staff: $this->staff,
    );

    expect($appointment->encounter)->not->toBeNull()
        ->and($appointment->encounter->status)->toBe(EncounterStatus::Planned);
});
