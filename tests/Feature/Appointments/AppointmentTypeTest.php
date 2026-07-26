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

test('appointment type seeder creates the four canonical types', function () {
    $this->seed(AppointmentTypeSeeder::class);

    $types = AppointmentType::query()->orderBy('name')->get();

    expect($types)->toHaveCount(4)
        ->and($types->pluck('name')->all())->toBe([
            'Follow-up',
            'New Patient',
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

    expect($newPatient->duration_minutes)->toBe(30)
        ->and($followUp->duration_minutes)->toBe(15)
        ->and($routine->duration_minutes)->toBe(30)
        ->and($referral->duration_minutes)->toBe(30);
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
