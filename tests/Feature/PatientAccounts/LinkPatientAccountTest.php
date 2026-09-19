<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\LinkPatientAccount;
use App\Enums\AuditEvent;
use App\Exceptions\PatientIdentityMismatchException;
use App\Models\AuditLog;
use App\Models\Conversation;
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

test('the canonical action links a compatible account and records a safe audit', function (): void {
    $lookupHash = app(CreateContactLookupHash::class);
    $account = User::factory()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'role_id' => Role::where('name', Role::Patient)->value('id'),
    ]);
    $patient = Patient::factory()->unlinked()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
        'phone_lookup_hash' => $lookupHash->forPhone('+639171234567'),
        'identity_review_required' => true,
    ]);
    PatientAccountContact::factory()->phone('+639171234567')->verified()->create([
        'user_id' => $account->id,
    ]);
    $conversation = Conversation::query()->create([
        'account_user_id' => $account->id,
        'patient_id' => null,
    ]);

    $result = app(LinkPatientAccount::class)->handle(
        account: $account,
        patient: $patient,
        source: 'test',
        sourceId: 123,
        actorId: $account->id,
    );

    expect($result['patient']->user_id)->toBe($account->id)
        ->and($patient->fresh()->identity_review_required)->toBeFalse()
        ->and($conversation->fresh()->patient_id)->toBe($patient->id)
        ->and(AuditLog::query()
            ->where('subject_type', $patient->getMorphClass())
            ->where('subject_id', $patient->id)
            ->where('action', AuditEvent::PatientAccountLinked->value)
            ->firstOrFail()
            ->metadata)->toMatchArray([
                'account_id' => $account->id,
                'source' => 'test',
                'source_id' => 123,
                'matched_fields' => ['first_name', 'last_name', 'date_of_birth', 'verified_contact'],
            ]);
});

test('the canonical action rejects an incompatible account without mutating link state', function (): void {
    $account = User::factory()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'role_id' => Role::where('name', Role::Patient)->value('id'),
    ]);
    $patient = Patient::factory()->unlinked()->create([
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'date_of_birth' => '1980-01-01',
    ]);

    expect(fn (): array => app(LinkPatientAccount::class)->handle(
        account: $account,
        patient: $patient,
        source: 'test',
    ))->toThrow(PatientIdentityMismatchException::class);

    expect($patient->fresh()->user_id)->toBeNull()
        ->and(AuditLog::query()
            ->where('subject_type', $patient->getMorphClass())
            ->where('subject_id', $patient->id)
            ->where('action', AuditEvent::PatientAccountLinked->value)
            ->exists())->toBeFalse();
});
