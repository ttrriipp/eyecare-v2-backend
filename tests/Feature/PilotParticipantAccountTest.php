<?php

use App\Models\PilotParticipantAccount;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('pilot configuration is closed without explicit enablement and expiry', function (): void {
    expect(config('capstone_pilot.enabled'))->toBeFalse()
        ->and(config('capstone_pilot.expires_at'))->toBeNull()
        ->and(config('capstone_pilot.participant_limit'))->toBe(75)
        ->and(config('capstone_pilot.credential_disk'))->toBe('local');
});

test('pilot participant accounts expose a one-to-one user relationship', function (): void {
    $user = User::factory()->patient()->create();
    $account = PilotParticipantAccount::factory()->for($user)->create([
        'participant_code' => 'PILOT-0001',
        'expires_at' => now()->addDay(),
    ]);

    expect($account->user->is($user))->toBeTrue()
        ->and($user->pilotParticipantAccount->is($account))->toBeTrue()
        ->and($account->expires_at)->toBeInstanceOf(Carbon::class)
        ->and($account->revoked_at)->toBeNull();
});

test('the default factory creates a patient account without a linked patient', function (): void {
    $account = PilotParticipantAccount::factory()->create();

    expect($account->user->isPatient())->toBeTrue()
        ->and($account->user->patient)->toBeNull();
});

test('eligible scope requires an active patient account before its expiry', function (): void {
    $eligibleUser = User::factory()->patient()->create();
    $expiredUser = User::factory()->patient()->create();
    $revokedUser = User::factory()->patient()->create();
    $staffUser = User::factory()->staff()->create();

    $eligible = PilotParticipantAccount::factory()->for($eligibleUser)->create([
        'participant_code' => 'PILOT-ELIGIBLE',
        'expires_at' => now()->addDay(),
    ]);
    PilotParticipantAccount::factory()->for($expiredUser)->create([
        'participant_code' => 'PILOT-EXPIRED',
        'expires_at' => now()->subMinute(),
    ]);
    PilotParticipantAccount::factory()->for($revokedUser)->create([
        'participant_code' => 'PILOT-REVOKED',
        'expires_at' => now()->addDay(),
        'revoked_at' => now(),
    ]);
    PilotParticipantAccount::factory()->for($staffUser)->create([
        'participant_code' => 'PILOT-STAFF',
        'expires_at' => now()->addDay(),
    ]);

    expect(PilotParticipantAccount::query()->eligibleAt(now())->pluck('id')->all())
        ->toBe([$eligible->id]);
});

test('participant codes are unique at the database boundary', function (): void {
    PilotParticipantAccount::factory()->for(User::factory()->patient())->create([
        'participant_code' => 'PILOT-DUPLICATE',
    ]);

    expect(fn () => PilotParticipantAccount::factory()->for(User::factory()->patient())->create([
        'participant_code' => 'PILOT-DUPLICATE',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('a user can have only one pilot participant account', function (): void {
    $user = User::factory()->patient()->create();

    PilotParticipantAccount::factory()->for($user)->create([
        'participant_code' => 'PILOT-ONE',
    ]);

    expect(fn () => PilotParticipantAccount::factory()->for($user)->create([
        'participant_code' => 'PILOT-TWO',
    ]))->toThrow(UniqueConstraintViolationException::class);
});
