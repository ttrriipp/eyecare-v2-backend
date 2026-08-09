<?php

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Patient;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('appointment type seeder creates six canonical types', function () {
    $this->seed(AppointmentTypeSeeder::class);

    $types = AppointmentType::query()->orderBy('name')->get();

    expect($types)->toHaveCount(6)
        ->and($types->pluck('name')->all())->toBe([
            'Contact Lens Consultation',
            'Follow-up',
            'New Patient',
            'Problem/Urgent Visit',
            'Referral',
            'Routine Check-up',
        ]);
});

test('each appointment type has a configurable duration', function () {
    $this->seed(AppointmentTypeSeeder::class);

    $newPatient = AppointmentType::query()->where('name', 'New Patient')->first();
    $followUp = AppointmentType::query()->where('name', 'Follow-up')->first();
    $routine = AppointmentType::query()->where('name', 'Routine Check-up')->first();
    $referral = AppointmentType::query()->where('name', 'Referral')->first();

    expect($newPatient->duration_minutes)->toBe(45)
        ->and($followUp->duration_minutes)->toBe(15)
        ->and($routine->duration_minutes)->toBe(30)
        ->and($referral->duration_minutes)->toBe(45);
});

test('appointment types have an active state', function () {
    $this->seed(AppointmentTypeSeeder::class);

    $type = AppointmentType::query()->first();
    expect($type->is_active)->toBeTrue();
});

test('referral appointment type requires a referring source', function () {
    $this->seed(AppointmentTypeSeeder::class);

    $referral = AppointmentType::query()->where('name', 'Referral')->first();
    expect($referral->requires_referral)->toBeTrue();

    $newPatient = AppointmentType::query()->where('name', 'New Patient')->first();
    expect($newPatient->requires_referral)->toBeFalse();
});

test('appointment can be linked to an appointment type', function () {
    $this->seed(AppointmentTypeSeeder::class);
    $patient = Patient::factory()->create();

    $type = AppointmentType::query()->where('name', 'New Patient')->first();

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'appointment_type_id' => $type->id,
    ]);

    expect($appointment->appointmentType->id)->toBe($type->id)
        ->and($appointment->appointmentType->name)->toBe('New Patient');
});

test('referral appointment can store referring source', function () {
    $this->seed(AppointmentTypeSeeder::class);
    $patient = Patient::factory()->create();

    $referral = AppointmentType::query()->where('name', 'Referral')->first();

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'appointment_type_id' => $referral->id,
        'referring_source' => 'Dr. Garcia - City Hospital',
    ]);

    expect($appointment->referring_source)->toBe('Dr. Garcia - City Hospital');
});

test('appointment stores a booked duration snapshot from appointment type', function () {
    $this->seed(AppointmentTypeSeeder::class);
    $patient = Patient::factory()->create();
    $type = AppointmentType::query()->where('name', 'New Patient')->first();

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'appointment_type_id' => $type->id,
        'duration_minutes' => $type->duration_minutes,
    ]);

    expect($appointment->duration_minutes)->toBe(45);
});

test('changing appointment type default does not alter existing appointments', function () {
    $this->seed(AppointmentTypeSeeder::class);
    $patient = Patient::factory()->create();
    $type = AppointmentType::query()->where('name', 'New Patient')->first();

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'appointment_type_id' => $type->id,
        'duration_minutes' => $type->duration_minutes,
    ]);

    expect($appointment->duration_minutes)->toBe(45);

    $type->update(['duration_minutes' => 60]);

    expect($appointment->fresh()->duration_minutes)->toBe(45)
        ->and($type->fresh()->duration_minutes)->toBe(60);
});

test('patient label falls back to internal name when null', function () {
    $type = AppointmentType::factory()->create([
        'name' => 'New Patient',
        'patient_label' => null,
    ]);

    expect($type->patient_label)->toBe('New Patient');
});

test('patient label returns explicit label when set', function () {
    $type = AppointmentType::factory()->create([
        'name' => 'New Patient',
        'patient_label' => 'First eye examination',
    ]);

    expect($type->patient_label)->toBe('First eye examination');
});

test('patient-visible scope returns only active and visible types', function () {
    AppointmentType::factory()->create(['is_active' => true, 'is_patient_visible' => true]);
    AppointmentType::factory()->internalOnly()->create(['is_active' => true]);
    AppointmentType::factory()->inactive()->create(['is_patient_visible' => true]);

    $visible = AppointmentType::patientVisible()->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->is_active)->toBeTrue()
        ->and($visible->first()->is_patient_visible)->toBeTrue();
});

test('active scope returns all active types including internal-only', function () {
    AppointmentType::factory()->create(['is_active' => true, 'is_patient_visible' => true]);
    AppointmentType::factory()->internalOnly()->create(['is_active' => true]);
    AppointmentType::factory()->inactive()->create();

    $active = AppointmentType::active()->get();

    expect($active)->toHaveCount(2);
});

test('is_patient_visible defaults to true', function () {
    $type = AppointmentType::factory()->create();

    expect($type->is_patient_visible)->toBeTrue();
});

test('is_patient_visible can be set to false', function () {
    $type = AppointmentType::factory()->internalOnly()->create();

    expect($type->is_patient_visible)->toBeFalse();
});
