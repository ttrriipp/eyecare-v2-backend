<?php

use App\Filament\Resources\Appointments\Pages\HealthRecord;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\PatientIntake;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
});

test('optometrist sees complete health record with clinical findings', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $intake = PatientIntake::factory()->create([
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'chief_complaint' => 'Blurred vision',
    ]);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(HealthRecord::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('Patient Health Record')
        ->assertSee('Blurred vision')
        ->assertSee('Clinical Encounter');
});

test('receptionist sees health record without clinical findings', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $patient = Patient::factory()->create();
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $intake = PatientIntake::factory()->create([
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'chief_complaint' => 'Headache',
    ]);

    $this->actingAs($staff);

    Livewire::test(HealthRecord::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('Patient Health Record')
        ->assertSee('Headache')
        ->assertDontSee('Clinical Encounter');
});

test('health record shows appointment type and referral', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'referring_source' => 'Dr. Garcia',
    ]);

    $this->actingAs($optometrist);

    Livewire::test(HealthRecord::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('Dr. Garcia');
});

test('health record shows patient demographics', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create([
        'full_name' => 'Juan dela Cruz',
        'gender' => 'male',
        'occupation' => 'Engineer',
    ]);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($optometrist);

    Livewire::test(HealthRecord::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('Juan dela Cruz')
        ->assertSee('Male')
        ->assertSee('Engineer');
});
