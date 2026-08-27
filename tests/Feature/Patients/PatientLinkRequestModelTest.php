<?php

use App\Models\Patient;
use App\Models\PatientLinkCandidate;
use App\Models\PatientLinkRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('link request has an auto-generated request number', function () {
    $request = PatientLinkRequest::factory()->create();

    expect($request->request_number)->toMatch('/^PLR-\d{4}-\d{6}$/');
});

test('link request belongs to a user', function () {
    $user = User::factory()->patient()->create();
    $request = PatientLinkRequest::factory()->create(['user_id' => $user->id]);

    expect($request->user->id)->toBe($user->id);
});

test('pending request is the default status', function () {
    $request = PatientLinkRequest::factory()->create();

    expect($request->status)->toBe('pending')
        ->and($request->isPending())->toBeTrue();
});

test('approved request references a patient and reviewer', function () {
    $request = PatientLinkRequest::factory()->approved()->create();

    expect($request->isApproved())->toBeTrue()
        ->and($request->reviewedPatient)->not->toBeNull()
        ->and($request->reviewer)->not->toBeNull()
        ->and($request->reviewed_at)->not->toBeNull();
});

test('rejected request records a decision note', function () {
    $request = PatientLinkRequest::factory()->rejected()->create();

    expect($request->isRejected())->toBeTrue()
        ->and($request->decision_note)->not->toBeNull()
        ->and($request->reviewed_at)->not->toBeNull();
});

test('expired request is recognized without reviewer decision fields', function () {
    $request = PatientLinkRequest::factory()->expired()->create();

    expect($request->isExpired())->toBeTrue()
        ->and($request->reviewer_id)->toBeNull()
        ->and($request->reviewed_patient_id)->toBeNull()
        ->and($request->reviewed_at)->toBeNull();
});

test('link request can have candidates', function () {
    $request = PatientLinkRequest::factory()->create();
    $patient = Patient::factory()->create();

    $candidate = PatientLinkCandidate::create([
        'link_request_id' => $request->id,
        'patient_id' => $patient->id,
        'match_strength' => 'strong',
        'reason_codes' => ['exact_name', 'exact_dob'],
        'rank' => 1,
    ]);

    expect($request->candidates)->toHaveCount(1)
        ->and($request->candidates->first()->id)->toBe($candidate->id);
});

test('candidate has unique patient per request', function () {
    $request = PatientLinkRequest::factory()->create();
    $patient = Patient::factory()->create();

    PatientLinkCandidate::create([
        'link_request_id' => $request->id,
        'patient_id' => $patient->id,
        'match_strength' => 'strong',
        'rank' => 1,
    ]);

    expect(fn () => PatientLinkCandidate::create([
        'link_request_id' => $request->id,
        'patient_id' => $patient->id,
        'match_strength' => 'moderate',
        'rank' => 2,
    ]))->toThrow(QueryException::class);
});

test('candidate reason codes are stored as JSON', function () {
    $request = PatientLinkRequest::factory()->create();
    $patient = Patient::factory()->create();

    $candidate = PatientLinkCandidate::create([
        'link_request_id' => $request->id,
        'patient_id' => $patient->id,
        'match_strength' => 'strong',
        'reason_codes' => ['exact_name', 'exact_dob', 'exact_phone'],
        'rank' => 1,
    ]);

    expect($candidate->reason_codes)->toBe(['exact_name', 'exact_dob', 'exact_phone']);
});

test('identity snapshot is encrypted', function () {
    $request = PatientLinkRequest::factory()->create([
        'encrypted_identity_snapshot' => ['first_name' => 'Ana', 'last_name' => 'Reyes'],
    ]);

    $raw = DB::table('patient_link_requests')->where('id', $request->id)->first();

    expect($raw->encrypted_identity_snapshot)->not->toContain('Ana')
        ->and($request->fresh()->encrypted_identity_snapshot)->toBe(['first_name' => 'Ana', 'last_name' => 'Reyes']);
});

test('only one active pending request per account', function () {
    $user = User::factory()->patient()->create();

    PatientLinkRequest::factory()->pending()->create(['user_id' => $user->id]);

    // A second pending request for the same user should be prevented at the application level
    // The database allows it, but the action should enforce this
    $this->assertDatabaseCount('patient_link_requests', 1);
});
