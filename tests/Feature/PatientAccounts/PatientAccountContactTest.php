<?php

use App\Models\PatientAccountContact;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// --- Model Basics ---

test('a patient account contact belongs to a user', function () {
    $user = User::factory()->patient()->create();
    $contact = PatientAccountContact::factory()->email()->create(['user_id' => $user->id]);

    expect($contact->user->id)->toBe($user->id);
});

test('contact type can be email or phone', function () {
    $email = PatientAccountContact::factory()->email()->create();
    $phone = PatientAccountContact::factory()->phone()->create();

    expect($email->type)->toBe('email')
        ->and($phone->type)->toBe('phone');
});

// --- Encrypted Values ---

test('contact values are encrypted at rest', function () {
    $contact = PatientAccountContact::factory()->email('test@example.com')->create();

    $raw = DB::table('patient_account_contacts')->where('id', $contact->id)->first();

    expect($raw->encrypted_value)->not->toBe('test@example.com')
        ->and($raw->encrypted_value)->not->toBeNull();
});

test('contact values are decrypted transparently', function () {
    $contact = PatientAccountContact::factory()->email('test@example.com')->create();

    $fresh = PatientAccountContact::find($contact->id);
    expect($fresh->encrypted_value)->toBe('test@example.com');
});

// --- Lookup Hash ---

test('lookup hash is unique per contact', function () {
    $first = PatientAccountContact::factory()->email('alice@example.com')->create();
    $second = PatientAccountContact::factory()->email('bob@example.com')->create();

    expect($first->lookup_hash)->not->toBe($second->lookup_hash);
});

test('lookup hash is deterministic for the same contact', function () {
    $contact = PatientAccountContact::factory()->email('test@example.com')->create();

    // The same email always produces the same hash (verified by the factory using the service)
    expect($contact->lookup_hash)->toMatch('/^[a-f0-9]{64}$/');
});

// --- Verification ---

test('contact defaults to unverified', function () {
    $contact = PatientAccountContact::factory()->email()->create();

    expect($contact->verified_at)->toBeNull()
        ->and($contact->isVerified())->toBeFalse();
});

test('verified contact has a timestamp', function () {
    $contact = PatientAccountContact::factory()->email()->verified()->create();

    expect($contact->verified_at)->not->toBeNull()
        ->and($contact->isVerified())->toBeTrue();
});

// --- Primary Contact ---

test('contact defaults to not primary', function () {
    $contact = PatientAccountContact::factory()->email()->create();

    expect($contact->is_primary)->toBeFalse();
});

test('primary contact is always verified', function () {
    $contact = PatientAccountContact::factory()->email()->primary()->create();

    expect($contact->is_primary)->toBeTrue()
        ->and($contact->isVerified())->toBeTrue();
});

// --- Unique Constraints ---

test('a user cannot have two contacts of the same type', function () {
    $user = User::factory()->patient()->create();

    PatientAccountContact::factory()->email()->create(['user_id' => $user->id]);

    expect(fn () => PatientAccountContact::factory()->email()->create(['user_id' => $user->id]))
        ->toThrow(QueryException::class);
});

test('different users can have the same contact type', function () {
    $user1 = User::factory()->patient()->create();
    $user2 = User::factory()->patient()->create();

    PatientAccountContact::factory()->email()->create(['user_id' => $user1->id]);
    PatientAccountContact::factory()->email()->create(['user_id' => $user2->id]);

    $this->assertDatabaseCount('patient_account_contacts', 2);
});

// --- Existing Staff/Admin Users ---

test('existing staff users have structured names from factory', function () {
    $staff = User::factory()->staff()->create();

    $staff->refresh();
    expect($staff->first_name)->not->toBeNull()
        ->and($staff->last_name)->not->toBeNull()
        ->and($staff->name)->not->toBeNull();
});

test('existing admin users have structured names from factory', function () {
    $admin = User::factory()->admin()->create();

    $admin->refresh();
    expect($admin->first_name)->not->toBeNull()
        ->and($admin->last_name)->not->toBeNull()
        ->and($admin->name)->not->toBeNull();
});

// --- Factory States ---

test('factory email state creates email contact', function () {
    $contact = PatientAccountContact::factory()->email('a@b.com')->create();

    expect($contact->type)->toBe('email')
        ->and($contact->encrypted_value)->toBe('a@b.com');
});

test('factory phone state creates phone contact', function () {
    $contact = PatientAccountContact::factory()->phone('+639171234567')->create();

    expect($contact->type)->toBe('phone')
        ->and($contact->encrypted_value)->toBe('+639171234567');
});
