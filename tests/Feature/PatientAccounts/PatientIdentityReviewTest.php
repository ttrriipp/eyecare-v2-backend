<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\ResolvePatientIdentityReview;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientAccountContact;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('a linked patient identity edit marks review once without unlinking', function (): void {
    $lookupHash = app(CreateContactLookupHash::class);
    $account = User::factory()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'role_id' => Role::where('name', Role::Patient)->value('id'),
    ]);
    PatientAccountContact::factory()->phone('+639171234567')->verified()->create([
        'user_id' => $account->id,
    ]);
    $patient = Patient::factory()->unlinked()->create([
        'user_id' => $account->id,
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
        'phone_lookup_hash' => $lookupHash->forPhone('+639171234567'),
    ]);

    $patient->update(['first_name' => 'Maria']);
    $patient->update(['last_name' => 'Santos']);

    expect($patient->fresh()->user_id)->toBe($account->id)
        ->and($patient->fresh()->identity_review_required)->toBeTrue()
        ->and(AuditLog::query()
            ->where('subject_type', $patient->getMorphClass())
            ->where('subject_id', $patient->id)
            ->where('action', AuditEvent::PatientIdentityReviewRequired->value)
            ->count())->toBe(1);
});

test('review resolution requires a fresh compatible pair and records the decision', function (): void {
    $lookupHash = app(CreateContactLookupHash::class);
    $account = User::factory()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'role_id' => Role::where('name', Role::Patient)->value('id'),
    ]);
    PatientAccountContact::factory()->phone('+639171234567')->verified()->create([
        'user_id' => $account->id,
    ]);
    $patient = Patient::factory()->unlinked()->create([
        'user_id' => $account->id,
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
        'phone_lookup_hash' => $lookupHash->forPhone('+639171234567'),
        'identity_review_required' => true,
    ]);
    $reviewer = User::factory()->staff()->create();

    app(ResolvePatientIdentityReview::class)->handle($patient, $reviewer);

    expect($patient->fresh()->identity_review_required)->toBeFalse()
        ->and(AuditLog::query()
            ->where('subject_id', $patient->id)
            ->where('action', AuditEvent::PatientIdentityReviewResolved->value)
            ->where('actor_id', $reviewer->id)
            ->exists())->toBeTrue();
});

test('review resolution cannot clear an incompatible pair', function (): void {
    $account = User::factory()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'role_id' => Role::where('name', Role::Patient)->value('id'),
    ]);
    $patient = Patient::factory()->create([
        'user_id' => $account->id,
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'date_of_birth' => '1980-01-01',
        'identity_review_required' => true,
    ]);
    $reviewer = User::factory()->staff()->create();

    expect(fn () => app(ResolvePatientIdentityReview::class)->handle($patient, $reviewer))
        ->toThrow(ValidationException::class, 'must match');

    expect($patient->fresh()->identity_review_required)->toBeTrue()
        ->and(AuditLog::query()
            ->where('subject_id', $patient->id)
            ->where('action', AuditEvent::PatientIdentityReviewResolved->value)
            ->exists())->toBeFalse();
});
