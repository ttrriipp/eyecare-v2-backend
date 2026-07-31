<?php

use App\Actions\Encounters\AuditLegacyPatientIntakes;
use App\Models\Appointment;
use App\Models\PatientIntake;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-31 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

test('audit reports no dependencies when no intakes exist', function () {
    $results = app(AuditLegacyPatientIntakes::class)->handle();

    expect($results['total_intakes'])->toBe(0)
        ->and($results['active_intakes'])->toBe(0)
        ->and($results['cleanup_ready'])->toBeTrue();
});

test('audit reports active intakes', function () {
    $appointment = Appointment::factory()->create();
    PatientIntake::factory()->create([
        'patient_id' => $appointment->patient_id,
        'appointment_id' => $appointment->id,
    ]);

    $results = app(AuditLegacyPatientIntakes::class)->handle();

    expect($results['total_intakes'])->toBe(1)
        ->and($results['active_intakes'])->toBe(1)
        ->and($results['cleanup_ready'])->toBeFalse();
});

test('audit reports cleanup ready when intakes are for past fulfilled appointments', function () {
    $appointment = Appointment::factory()->fulfilled()->create([
        'scheduled_at' => now()->subDay(),
    ]);
    PatientIntake::factory()->verified()->create([
        'patient_id' => $appointment->patient_id,
        'appointment_id' => $appointment->id,
    ]);

    $results = app(AuditLegacyPatientIntakes::class)->handle();

    expect($results['total_intakes'])->toBe(1)
        ->and($results['active_intakes'])->toBe(0)
        ->and($results['cleanup_ready'])->toBeTrue();
});

test('command runs successfully', function () {
    $this->artisan('encounters:audit-legacy-intakes')
        ->assertSuccessful();
});
