<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\RankPatientCandidates;
use App\Models\Patient;
use App\Models\PatientAccountContact;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->ranker = app(RankPatientCandidates::class);
    $this->lookupHash = app(CreateContactLookupHash::class);
});

test('snapshot matching finds patient by exact phone hash', function () {
    $patient = Patient::factory()->create([
        'phone' => '09171234567',
    ]);

    $snapshot = [
        'first_name' => $patient->first_name,
        'last_name' => $patient->last_name,
        'date_of_birth' => $patient->date_of_birth->format('Y-m-d'),
        'verified_contact_type' => 'phone',
        'verified_contact_hash' => $this->lookupHash->forPhone('09171234567'),
    ];

    $candidates = $this->ranker->fromSnapshot($snapshot);

    expect($candidates)->toHaveCount(1)
        ->and($candidates->first()['patient']->id)->toBe($patient->id)
        ->and($candidates->first()['strength'])->toBe('strong')
        ->and($candidates->first()['reasons'])->toContain('exact_phone');
});

test('snapshot matching finds patient by exact name and dob', function () {
    $patient = Patient::factory()->create([
        'first_name' => 'Ana',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
    ]);

    $snapshot = [
        'first_name' => 'Ana',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'verified_contact_type' => 'phone',
        'verified_contact_hash' => $this->lookupHash->forPhone('09999999999'),
    ];

    $candidates = $this->ranker->fromSnapshot($snapshot);

    expect($candidates)->toHaveCount(1)
        ->and($candidates->first()['patient']->id)->toBe($patient->id)
        ->and($candidates->first()['strength'])->toBe('moderate')
        ->and($candidates->first()['reasons'])->toContain('exact_name')
        ->and($candidates->first()['reasons'])->toContain('exact_dob');
});

test('snapshot matching returns empty for no match', function () {
    $snapshot = [
        'first_name' => 'Unknown',
        'last_name' => 'Patient',
        'date_of_birth' => '2000-01-01',
        'verified_contact_type' => 'phone',
        'verified_contact_hash' => $this->lookupHash->forPhone('09000000000'),
    ];

    $candidates = $this->ranker->fromSnapshot($snapshot);

    expect($candidates)->toHaveCount(0);
});

test('snapshot matching excludes linked patients', function () {
    $patient = Patient::factory()->create([
        'phone' => '09171234567',
    ]);

    // Create a separate user and link the patient
    $user = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);
    $patient->update(['user_id' => $user->id]);

    $snapshot = [
        'first_name' => $patient->first_name,
        'last_name' => $patient->last_name,
        'date_of_birth' => $patient->date_of_birth->format('Y-m-d'),
        'verified_contact_type' => 'phone',
        'verified_contact_hash' => $this->lookupHash->forPhone('09171234567'),
    ];

    $candidates = $this->ranker->fromSnapshot($snapshot);

    expect($candidates)->toHaveCount(0);
});

test('snapshot matching deduplicates patients', function () {
    $patient = Patient::factory()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '09171234567',
    ]);

    $snapshot = [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'verified_contact_type' => 'phone',
        'verified_contact_hash' => $this->lookupHash->forPhone('09171234567'),
    ];

    $candidates = $this->ranker->fromSnapshot($snapshot);

    expect($candidates)->toHaveCount(1);
});

test('existing account-based ranking still works', function () {
    $user = User::factory()->patient()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
    ]);

    PatientAccountContact::factory()->create([
        'user_id' => $user->id,
        'type' => 'phone',
        'lookup_hash' => $this->lookupHash->forPhone('09171234567'),
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $patient = Patient::factory()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '09171234567',
    ]);

    $candidates = $this->ranker->handle($user);

    expect($candidates)->toHaveCount(1)
        ->and($candidates->first()['patient']->id)->toBe($patient->id);
});

test('strong match requires contact plus name or dob', function () {
    $patient = Patient::factory()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '09171234567',
    ]);

    $snapshot = [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'verified_contact_type' => 'phone',
        'verified_contact_hash' => $this->lookupHash->forPhone('09171234567'),
    ];

    $candidates = $this->ranker->fromSnapshot($snapshot);

    expect($candidates->first()['strength'])->toBe('strong')
        ->and($candidates->first()['reasons'])->toContain('exact_phone')
        ->and($candidates->first()['reasons'])->toContain('exact_name')
        ->and($candidates->first()['reasons'])->toContain('exact_dob');
});
