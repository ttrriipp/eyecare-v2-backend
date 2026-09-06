<?php

use App\Actions\Auth\AuthenticatePilotParticipant;
use App\Models\PilotParticipantAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config([
        'capstone_pilot.enabled' => true,
        'capstone_pilot.expires_at' => now()->addDays(7),
    ]);
});

test('eligible participant credentials issue a token bounded by the pilot expiry', function (): void {
    $pilotEndsAt = now()->addDays(3)->startOfSecond();
    config(['capstone_pilot.expires_at' => $pilotEndsAt]);

    $user = User::factory()->patient()->create([
        'password' => Hash::make('participant-secret'),
    ]);
    PilotParticipantAccount::factory()->for($user)->create([
        'participant_code' => 'PILOT-0001',
        'expires_at' => $pilotEndsAt->copy()->addDay(),
    ]);

    $result = app(AuthenticatePilotParticipant::class)->handle(
        participantCode: '  pilot-0001 ',
        password: 'participant-secret',
        deviceName: 'Android',
        installationId: 'install-0001',
    );

    $token = PersonalAccessToken::query()->where('tokenable_id', $user->id)->firstOrFail();

    expect($result['step_up_required'])->toBeFalse()
        ->and($result['token'])->toContain('|')
        ->and($result['user']->is($user))->toBeTrue()
        ->and($token->name)->toBe('Android')
        ->and($token->installation_id)->toBe('install-0001')
        ->and($token->expires_at->equalTo($pilotEndsAt))->toBeTrue();
});

test('pilot expiry never extends the normal patient token lifetime', function (): void {
    $pilotEndsAt = now()->addDays(60)->startOfSecond();
    config(['capstone_pilot.expires_at' => $pilotEndsAt]);

    $user = User::factory()->patient()->create([
        'password' => Hash::make('participant-secret'),
    ]);
    PilotParticipantAccount::factory()->for($user)->create([
        'participant_code' => 'PILOT-0002',
        'expires_at' => $pilotEndsAt,
    ]);

    app(AuthenticatePilotParticipant::class)->handle(
        participantCode: 'PILOT-0002',
        password: 'participant-secret',
    );

    $token = PersonalAccessToken::query()->where('tokenable_id', $user->id)->firstOrFail();

    expect($token->expires_at->isBefore($pilotEndsAt))->toBeTrue()
        ->and($token->expires_at->diffInDays(now()))->toBeLessThanOrEqual(30);
});

test('participant expiry also bounds the issued token', function (): void {
    $pilotEndsAt = now()->addDays(7)->startOfSecond();
    $accountEndsAt = now()->addHours(2)->startOfSecond();
    config(['capstone_pilot.expires_at' => $pilotEndsAt]);

    $user = User::factory()->patient()->create([
        'password' => Hash::make('participant-secret'),
    ]);
    PilotParticipantAccount::factory()->for($user)->create([
        'participant_code' => 'PILOT-0003',
        'expires_at' => $accountEndsAt,
    ]);

    app(AuthenticatePilotParticipant::class)->handle(
        participantCode: 'PILOT-0003',
        password: 'participant-secret',
    );

    $token = PersonalAccessToken::query()->where('tokenable_id', $user->id)->firstOrFail();

    expect($token->expires_at->equalTo($accountEndsAt))->toBeTrue();
});

test('pilot authentication is unavailable unless enabled with a future expiry', function (): void {
    $authentication = app(AuthenticatePilotParticipant::class);
    $now = now();

    config(['capstone_pilot.enabled' => false]);
    expect($authentication->isAvailableAt($now))->toBeFalse();

    config([
        'capstone_pilot.enabled' => true,
        'capstone_pilot.expires_at' => null,
    ]);
    expect($authentication->isAvailableAt($now))->toBeFalse();

    config(['capstone_pilot.expires_at' => $now->copy()->subSecond()]);
    expect($authentication->isAvailableAt($now))->toBeFalse();

    config(['capstone_pilot.expires_at' => 'not-a-timestamp']);
    expect($authentication->isAvailableAt($now))->toBeFalse();
});

test('all invalid participant states return the same generic validation error', function (): void {
    $password = 'participant-secret';
    $validUser = User::factory()->patient()->create(['password' => Hash::make($password)]);
    PilotParticipantAccount::factory()->for($validUser)->create([
        'participant_code' => 'PILOT-VALID',
    ]);

    $expiredUser = User::factory()->patient()->create(['password' => Hash::make($password)]);
    PilotParticipantAccount::factory()->for($expiredUser)->create([
        'participant_code' => 'PILOT-EXPIRED',
        'expires_at' => now()->subSecond(),
    ]);

    $revokedUser = User::factory()->patient()->create(['password' => Hash::make($password)]);
    PilotParticipantAccount::factory()->for($revokedUser)->create([
        'participant_code' => 'PILOT-REVOKED',
        'revoked_at' => now(),
    ]);

    $staffUser = User::factory()->staff()->create(['password' => Hash::make($password)]);
    PilotParticipantAccount::factory()->for($staffUser)->create([
        'participant_code' => 'PILOT-STAFF',
    ]);

    $authentication = app(AuthenticatePilotParticipant::class);
    $errors = [];

    foreach ([
        ['PILOT-UNKNOWN', 'pilot-dummy'],
        ['PILOT-VALID', 'wrong-password'],
        ['PILOT-EXPIRED', $password],
        ['PILOT-REVOKED', $password],
        ['PILOT-STAFF', $password],
    ] as [$code, $attemptedPassword]) {
        try {
            $authentication->handle($code, $attemptedPassword);
        } catch (ValidationException $exception) {
            $errors[] = $exception->errors();
        }
    }

    expect($errors)->toHaveCount(5)
        ->and($errors[1])->toBe($errors[0])
        ->and($errors[2])->toBe($errors[0])
        ->and($errors[3])->toBe($errors[0])
        ->and($errors[4])->toBe($errors[0])
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});
