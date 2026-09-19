<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\PatientAccountIdentityMatcher;
use App\Models\Patient;
use App\Models\PatientAccountContact;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('the matcher accepts any verified contact that matches by type', function (): void {
    $lookupHash = app(CreateContactLookupHash::class);
    $account = User::factory()->create([
        'first_name' => 'Ana',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'role_id' => Role::where('name', Role::Patient)->value('id'),
    ]);
    $patient = Patient::factory()->unlinked()->create([
        'first_name' => '  ANA',
        'middle_name' => null,
        'last_name' => 'Reyes  ',
        'date_of_birth' => '1990-05-15',
        'contact_email' => 'ana@example.com',
        'contact_email_lookup_hash' => $lookupHash->forEmail('ana@example.com'),
    ]);

    PatientAccountContact::factory()->phone('+639171234567')->verified()->create([
        'user_id' => $account->id,
    ]);
    PatientAccountContact::factory()->email('ana@example.com')->verified()->create([
        'user_id' => $account->id,
    ]);

    $match = app(PatientAccountIdentityMatcher::class)->handle($account, $patient);

    expect($match->isEligible())->toBeTrue()
        ->and($match->matchedFields)->toContain('verified_contact')
        ->and($match->mismatchedFields)->toBeEmpty()
        ->and($match->missingFields)->toBeEmpty();
});

test('the matcher reports missing and mismatched evidence using field names only', function (): void {
    $account = User::factory()->create([
        'first_name' => 'Ana',
        'middle_name' => 'Marie',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'role_id' => Role::where('name', Role::Patient)->value('id'),
    ]);
    $patient = Patient::factory()->unlinked()->create([
        'first_name' => 'Maria',
        'middle_name' => 'Marie',
        'last_name' => 'Reyes',
        'date_of_birth' => '1991-05-15',
    ]);

    $match = app(PatientAccountIdentityMatcher::class)->handle($account, $patient);

    expect($match->isEligible())->toBeFalse()
        ->and($match->mismatchedFields)->toContain('first_name', 'date_of_birth')
        ->and($match->missingFields)->toContain('verified_contact')
        ->and(collect($match->toArray())->flatten()->every(fn (mixed $value): bool => is_string($value)))->toBeTrue();
});

test('a missing middle name is neutral when all required evidence matches', function (): void {
    $lookupHash = app(CreateContactLookupHash::class);
    $account = User::factory()->create([
        'first_name' => 'Ana',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'role_id' => Role::where('name', Role::Patient)->value('id'),
    ]);
    $patient = Patient::factory()->unlinked()->create([
        'first_name' => 'Ana',
        'middle_name' => 'Marie',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
        'phone_lookup_hash' => $lookupHash->forPhone('+639171234567'),
    ]);
    PatientAccountContact::factory()->phone('+639171234567')->verified()->create([
        'user_id' => $account->id,
    ]);

    $match = app(PatientAccountIdentityMatcher::class)->handle($account, $patient);

    expect($match->isEligible())->toBeTrue()
        ->and($match->matchedFields)->not->toContain('middle_name');
});
