<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Models\Patient;
use App\Models\PatientAccountContact;
use App\Models\PatientLinkRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// --- Successful Submission ---

test('linked account can submit a link request', function () {
    $user = User::factory()->patient()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/patient-link-requests');

    // Linked accounts already have a patient, so this should fail
    $response->assertUnprocessable();
});

test('unlinked account can submit a link request', function () {
    $user = User::factory()->create([
        'role_id' => Role::where('name', 'patient')->first()->id,
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/patient-link-requests');

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['request_number', 'status', 'submitted_at']]);
});

test('repeated submission returns existing request', function () {
    $user = User::factory()->create([
        'role_id' => Role::where('name', 'patient')->first()->id,
    ]);

    $first = $this->actingAs($user)->postJson('/api/v1/patient-link-requests');
    $second = $this->actingAs($user)->postJson('/api/v1/patient-link-requests');

    $first->assertCreated();
    $second->assertCreated();

    expect($first->json('data.request_number'))->toBe($second->json('data.request_number'));
});

// --- Candidate Ranking ---

test('request with matching patient creates candidates', function () {
    $lookupHash = app(CreateContactLookupHash::class);
    $emailHash = $lookupHash->forEmail('ana@example.com');

    $patient = Patient::factory()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'contact_email' => 'ana@example.com',
        'contact_email_lookup_hash' => $emailHash,
    ]);

    $user = User::factory()->create([
        'role_id' => Role::where('name', 'patient')->first()->id,
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
    ]);

    PatientAccountContact::factory()->email('ana@example.com')->verified()->primary()->create([
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/patient-link-requests')
        ->assertCreated();

    $request = PatientLinkRequest::where('user_id', $user->id)->first();
    expect($request->candidates)->toHaveCount(1)
        ->and($request->candidates->first()->match_strength)->toBe('strong');
});

test('request with no matching patient creates no candidates', function () {
    $user = User::factory()->create([
        'role_id' => Role::where('name', 'patient')->first()->id,
        'first_name' => 'Unknown',
        'last_name' => 'Person',
        'date_of_birth' => '2000-01-01',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/patient-link-requests')
        ->assertCreated();

    $request = PatientLinkRequest::where('user_id', $user->id)->first();
    expect($request->candidates)->toHaveCount(0);
});

// --- Current Request ---

test('current endpoint returns active request', function () {
    $user = User::factory()->create([
        'role_id' => Role::where('name', 'patient')->first()->id,
    ]);

    $this->actingAs($user)->postJson('/api/v1/patient-link-requests');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/patient-link-requests/current');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['request_number', 'status', 'submitted_at']]);
});

test('current endpoint returns 204 when no active request', function () {
    $user = User::factory()->create([
        'role_id' => Role::where('name', 'patient')->first()->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/patient-link-requests/current')
        ->assertNoContent();
});

// --- Mobile Response Safety ---

test('mobile response never exposes candidate details', function () {
    $patient = Patient::factory()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
    ]);

    $user = User::factory()->create([
        'role_id' => Role::where('name', 'patient')->first()->id,
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/patient-link-requests');

    $response->assertCreated();

    // Response should not contain patient names, numbers, or match details
    $json = $response->json();
    expect($json['data'])->not->toHaveKey('candidates')
        ->and($json['data'])->not->toHaveKey('patient_id')
        ->and($json['data'])->not->toHaveKey('patient_number');
});
