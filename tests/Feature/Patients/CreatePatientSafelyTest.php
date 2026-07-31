<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\Patients\SearchPatientDuplicates;
use App\Models\Patient;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// --- Duplicate Search ---

test('search finds patient by email lookup hash', function () {
    $lookupHash = app(CreateContactLookupHash::class);
    $emailHash = $lookupHash->forEmail('ana@example.com');

    $patient = Patient::factory()->create([
        'contact_email' => 'ana@example.com',
        'contact_email_lookup_hash' => $emailHash,
    ]);

    $results = app(SearchPatientDuplicates::class)->handle([
        'contact_email' => 'ana@example.com',
    ]);

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($patient->id);
});

test('search finds patient by phone lookup hash', function () {
    $lookupHash = app(CreateContactLookupHash::class);
    $phoneHash = $lookupHash->forPhone('+639171234567');

    $patient = Patient::factory()->create([
        'phone' => '+639171234567',
        'phone_lookup_hash' => $phoneHash,
    ]);

    $results = app(SearchPatientDuplicates::class)->handle([
        'phone' => '+639171234567',
    ]);

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($patient->id);
});

test('search finds patient by name and date of birth', function () {
    $patient = Patient::factory()->create([
        'full_name' => 'Ana Reyes',
        'date_of_birth' => '1990-05-15',
    ]);

    $results = app(SearchPatientDuplicates::class)->handle([
        'full_name' => 'Ana Reyes',
        'date_of_birth' => '1990-05-15',
    ]);

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($patient->id);
});

test('search returns empty when no matches', function () {
    $results = app(SearchPatientDuplicates::class)->handle([
        'full_name' => 'Unknown Person',
        'date_of_birth' => '2000-01-01',
    ]);

    expect($results)->toHaveCount(0);
});

test('search deduplicates results', function () {
    $lookupHash = app(CreateContactLookupHash::class);
    $emailHash = $lookupHash->forEmail('ana@example.com');

    $patient = Patient::factory()->create([
        'full_name' => 'Ana Reyes',
        'date_of_birth' => '1990-05-15',
        'contact_email' => 'ana@example.com',
        'contact_email_lookup_hash' => $emailHash,
    ]);

    $results = app(SearchPatientDuplicates::class)->handle([
        'contact_email' => 'ana@example.com',
        'full_name' => 'Ana Reyes',
        'date_of_birth' => '1990-05-15',
    ]);

    expect($results)->toHaveCount(1);
});
