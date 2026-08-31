<?php

use App\Models\AppointmentType;
use App\Models\AppointmentTypeVisitReasonPreset;
use App\Models\User;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->user = User::factory()->patient()->create();
});

test('appointment types endpoint requires authentication', function () {
    $this->getJson('/api/v1/appointment-types')
        ->assertUnauthorized();
});

test('linked and unlinked patient accounts can list visible active types', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-types')
        ->assertOk()
        ->assertJsonCount(6, 'data');
});

test('response contains only patient-safe fields', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-types')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'description',
                    'duration_minutes',
                    'requires_referral',
                    'visit_reason_presets' => [
                        '*' => ['id', 'label'],
                    ],
                ],
            ],
        ]);
});

test('active visit reason presets are returned in sort order', function () {
    $appointmentType = AppointmentType::query()
        ->where('name', 'Problem/Urgent Visit')
        ->firstOrFail();

    $blurredVision = AppointmentTypeVisitReasonPreset::factory()->for($appointmentType)->create([
        'label' => 'Blurred or reduced vision',
        'sort_order' => 20,
    ]);
    $eyePain = AppointmentTypeVisitReasonPreset::factory()->for($appointmentType)->create([
        'label' => 'Eye pain or discomfort',
        'sort_order' => 10,
    ]);
    AppointmentTypeVisitReasonPreset::factory()->for($appointmentType)->inactive()->create([
        'label' => 'Internal note',
        'sort_order' => 1,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-types')
        ->assertOk();

    $type = collect($response->json('data'))->firstWhere('id', $appointmentType->id);

    expect($type['visit_reason_presets'])->toBe([
        ['id' => $eyePain->id, 'label' => 'Eye pain or discomfort'],
        ['id' => $blurredVision->id, 'label' => 'Blurred or reduced vision'],
    ]);
});

test('appointment types without active visit reason presets return an empty array', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-types')
        ->assertOk();

    collect($response->json('data'))->each(function (array $appointmentType): void {
        expect($appointmentType['visit_reason_presets'])->toBe([]);
    });
});

test('inactive types are excluded', function () {
    AppointmentType::factory()->inactive()->create([
        'patient_label' => 'Hidden Type',
        'is_patient_visible' => true,
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-types')
        ->assertOk()
        ->assertJsonCount(6, 'data');
});

test('internal-only types are excluded', function () {
    AppointmentType::factory()->internalOnly()->create([
        'name' => 'Internal Type',
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-types')
        ->assertOk()
        ->assertJsonCount(6, 'data');
});

test('patient label is used instead of internal name', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-types')
        ->assertOk();

    $names = collect($response->json('data'))->pluck('name')->toArray();

    // Patient labels from the seeder, not internal names
    expect($names)->toContain('Contact lens consultation')
        ->and($names)->toContain('First eye examination')
        ->and($names)->toContain('Regular eye examination');
});

test('internal name is not exposed when patient label differs', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-types')
        ->assertOk();

    $data = $response->json('data');

    // Each type should return its patient_label as the name
    $newPatient = collect($data)->firstWhere('name', 'First eye examination');
    expect($newPatient)->not->toBeNull();

    // The response should not contain internal names that differ from patient labels
    $names = collect($data)->pluck('name')->toArray();
    expect($names)->not->toContain('New Patient')
        ->and($names)->not->toContain('Routine Check-up')
        ->and($names)->not->toContain('Problem/Urgent Visit')
        ->and($names)->not->toContain('Contact Lens Consultation');
});

test('types are ordered by internal name', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-types')
        ->assertOk();

    $names = collect($response->json('data'))->pluck('name')->toArray();

    // Order by internal name (Contact Lens, Follow-up, New Patient, Problem, Referral, Routine)
    expect($names)->toBe([
        'Contact lens consultation',
        'Follow-up requested by the optometrist',
        'First eye examination',
        'New or worsening eye concern',
        'Referral',
        'Regular eye examination',
    ]);
});
