<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
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

test('identity audit dry run reports safe reasons without changing links', function (): void {
    $lookupHash = app(CreateContactLookupHash::class);
    $account = User::factory()->create([
        'first_name' => 'Ana',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'role_id' => Role::where('name', Role::Patient)->value('id'),
    ]);
    PatientAccountContact::factory()->phone('+639171234567')->verified()->create([
        'user_id' => $account->id,
    ]);
    $patient = Patient::factory()->create([
        'user_id' => $account->id,
        'first_name' => 'Maria',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
        'phone_lookup_hash' => $lookupHash->forPhone('+639171234567'),
    ]);

    $this->artisan('patient-links:audit-identity')
        ->expectsOutputToContain('Mismatched')
        ->expectsOutputToContain('mismatched:first_name')
        ->assertExitCode(0);

    expect($patient->fresh()->identity_review_required)->toBeFalse()
        ->and(AuditLog::query()
            ->where('subject_id', $patient->id)
            ->where('action', AuditEvent::PatientIdentityReviewRequired->value)
            ->exists())->toBeFalse();
});

test('mark review is idempotent and keeps the active link', function (): void {
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
    ]);

    $this->artisan('patient-links:audit-identity', ['--mark-review' => true])
        ->expectsOutputToContain('Newly marked')
        ->assertExitCode(0);

    $this->artisan('patient-links:audit-identity', ['--mark-review' => true])
        ->expectsOutputToContain('Already marked')
        ->assertExitCode(0);

    expect($patient->fresh()->user_id)->toBe($account->id)
        ->and($patient->fresh()->identity_review_required)->toBeTrue()
        ->and(AuditLog::query()
            ->where('subject_id', $patient->id)
            ->where('action', AuditEvent::PatientIdentityReviewRequired->value)
            ->count())->toBe(1);
});
