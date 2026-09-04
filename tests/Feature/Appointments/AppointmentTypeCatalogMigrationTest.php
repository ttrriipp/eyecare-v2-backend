<?php

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\AppointmentTypeVisitReasonPreset;
use App\Models\Patient;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('seeder creates six canonical types idempotently', function () {
    $this->seed(AppointmentTypeSeeder::class);
    $count1 = AppointmentType::query()->count();

    $this->seed(AppointmentTypeSeeder::class);
    $count2 = AppointmentType::query()->count();

    expect($count1)->toBe(6)
        ->and($count2)->toBe(6);
});

test('seeder populates patient metadata for all canonical types', function () {
    $this->seed(AppointmentTypeSeeder::class);

    $types = AppointmentType::query()->get();

    $types->each(function (AppointmentType $type): void {
        expect($type->patient_label)->not->toBeNull()
            ->and($type->is_patient_visible)->toBeTrue();
    });
});

test('seeder changes New Patient from 30 to 45 minutes', function () {
    AppointmentType::query()->create([
        'name' => 'New Patient',
        'duration_minutes' => 30,
        'requires_referral' => false,
        'is_active' => true,
    ]);

    $this->seed(AppointmentTypeSeeder::class);

    $type = AppointmentType::query()->where('name', 'New Patient')->first();

    expect($type->duration_minutes)->toBe(45);
});

test('seeder changes Referral from 30 to 45 minutes', function () {
    AppointmentType::query()->create([
        'name' => 'Referral',
        'duration_minutes' => 30,
        'requires_referral' => true,
        'is_active' => true,
    ]);

    $this->seed(AppointmentTypeSeeder::class);

    $type = AppointmentType::query()->where('name', 'Referral')->first();

    expect($type->duration_minutes)->toBe(45);
});

test('seeder preserves clinic-customized New Patient duration', function () {
    AppointmentType::query()->create([
        'name' => 'New Patient',
        'duration_minutes' => 60,
        'requires_referral' => false,
        'is_active' => true,
    ]);

    $this->seed(AppointmentTypeSeeder::class);

    $type = AppointmentType::query()->where('name', 'New Patient')->first();

    expect($type->duration_minutes)->toBe(60);
});

test('seeder preserves clinic-customized Referral duration', function () {
    AppointmentType::query()->create([
        'name' => 'Referral',
        'duration_minutes' => 60,
        'requires_referral' => true,
        'is_active' => true,
    ]);

    $this->seed(AppointmentTypeSeeder::class);

    $type = AppointmentType::query()->where('name', 'Referral')->first();

    expect($type->duration_minutes)->toBe(60);
});

test('existing appointment duration snapshots unchanged after seeder', function () {
    $type = AppointmentType::query()->create([
        'name' => 'New Patient',
        'duration_minutes' => 30,
        'requires_referral' => false,
        'is_active' => true,
    ]);

    $patient = Patient::factory()->create();

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'appointment_type_id' => $type->id,
        'duration_minutes' => 30,
    ]);

    $this->seed(AppointmentTypeSeeder::class);

    expect($appointment->fresh()->duration_minutes)->toBe(30);
    expect($type->fresh()->duration_minutes)->toBe(45);
});

test('seeder adds Problem Urgent Visit type', function () {
    $this->seed(AppointmentTypeSeeder::class);

    $type = AppointmentType::query()->where('name', 'Problem/Urgent Visit')->first();

    expect($type)->not->toBeNull()
        ->and($type->duration_minutes)->toBe(30)
        ->and($type->requires_referral)->toBeFalse()
        ->and($type->patient_label)->toBe('New or worsening eye concern')
        ->and($type->is_patient_visible)->toBeTrue();
});

test('seeder adds Contact Lens Consultation type', function () {
    $this->seed(AppointmentTypeSeeder::class);

    $type = AppointmentType::query()->where('name', 'Contact Lens Consultation')->first();

    expect($type)->not->toBeNull()
        ->and($type->duration_minutes)->toBe(45)
        ->and($type->requires_referral)->toBeFalse()
        ->and($type->patient_label)->toBe('Contact lens consultation')
        ->and($type->is_patient_visible)->toBeTrue();
});

test('seeder adds ordered visit reason presets to every canonical type', function () {
    $this->seed(AppointmentTypeSeeder::class);

    $expectedPresets = [
        'New Patient' => [
            'First eye examination',
            'Blurred or reduced vision',
            'Eye strain or headaches',
        ],
        'Follow-up' => [
            'Follow-up after a recent prescription change',
            'Review test results',
            'Monitor an ongoing eye condition',
        ],
        'Routine Check-up' => [
            'Routine eye examination',
            'Prescription update',
            'Eye health screening',
        ],
        'Referral' => [
            'Referral from another provider',
            'Second opinion',
            'Specialist assessment',
        ],
        'Problem/Urgent Visit' => [
            'Blurred or reduced vision',
            'Eye pain or discomfort',
            'Redness or irritation',
            'Sudden flashes or floaters',
        ],
        'Contact Lens Consultation' => [
            'New contact lens fitting',
            'Contact lens prescription update',
            'Contact lens discomfort',
        ],
    ];

    foreach ($expectedPresets as $typeName => $labels) {
        $type = AppointmentType::query()->where('name', $typeName)->firstOrFail();

        expect($type->activeVisitReasonPresets()->pluck('label')->all())->toBe($labels);
    }
});

test('seeder does not duplicate visit reason presets when rerun', function () {
    $this->seed(AppointmentTypeSeeder::class);
    $firstCount = AppointmentTypeVisitReasonPreset::query()->count();

    $this->seed(AppointmentTypeSeeder::class);
    $secondCount = AppointmentTypeVisitReasonPreset::query()->count();

    expect($firstCount)->toBe(19)
        ->and($secondCount)->toBe($firstCount);
});
