<?php

use App\Actions\Auth\ResetPilotParticipantCredential;
use App\Actions\Auth\RevokePilotParticipants;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\PilotParticipantAccount;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config([
        'capstone_pilot.enabled' => true,
        'capstone_pilot.expires_at' => now()->addDays(7),
        'capstone_pilot.credential_disk' => 'local',
    ]);
    Storage::fake('local');

    Hash::driver()->setRounds(4);
});

afterEach(function (): void {
    Hash::driver()->setRounds(12);
});

function createManageablePilotAccount(
    string $participantCode = 'PILOT-0001',
    string $password = 'old-participant-password',
): PilotParticipantAccount {
    $patientRole = Role::query()->where('name', Role::Patient)->firstOrFail();
    $user = User::factory()->create([
        'first_name' => null,
        'middle_name' => null,
        'last_name' => null,
        'email' => null,
        'phone' => null,
        'password' => Hash::make($password),
        'role_id' => $patientRole->id,
    ]);
    $user->roles()->sync([$patientRole->id]);

    return PilotParticipantAccount::factory()->for($user)->create([
        'participant_code' => $participantCode,
        'expires_at' => now()->addDays(7),
        'revoked_at' => null,
    ]);
}

test('reset replaces a credential in a private file and invalidates every existing token', function (): void {
    $account = createManageablePilotAccount();
    $account->user->createToken('first-device');
    $account->user->createToken('second-device');

    $result = app(ResetPilotParticipantCredential::class)->handle(' pilot-0001 ');

    $files = Storage::disk('local')->allFiles('pilot/credentials');
    expect($result['manifest_path'])->toBe($files[0])
        ->and($files)->toHaveCount(1)
        ->and(Storage::disk('local')->getVisibility($files[0]))->toBe('private')
        ->and(PersonalAccessToken::query()->where('tokenable_id', $account->user_id)->count())->toBe(0)
        ->and($account->fresh()->revoked_at)->toBeNull();

    $manifest = json_decode(Storage::disk('local')->get($files[0]), true, 512, JSON_THROW_ON_ERROR);
    $newPassword = $manifest['participants'][0]['password'];
    $user = $account->user()->firstOrFail();

    expect($manifest['participants'])->toHaveCount(1)
        ->and($manifest['participants'][0]['participant_code'])->toBe('PILOT-0001')
        ->and(Hash::check($newPassword, $user->password))->toBeTrue()
        ->and(Hash::check('old-participant-password', $user->password))->toBeFalse()
        ->and($user->must_change_password)->toBeFalse()
        ->and($user->password_changed_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditEvent::ParticipantCredentialReset->value)->count())->toBe(1);

    $auditMetadata = AuditLog::query()
        ->where('action', AuditEvent::ParticipantCredentialReset->value)
        ->value('metadata');

    expect($auditMetadata)->not->toHaveKeys(['participant_code', 'password', 'device_name', 'installation_id']);
});

test('reset refuses unavailable, expired, revoked, and unknown participant accounts', function (): void {
    $expired = createManageablePilotAccount('PILOT-EXPIRED');
    $expired->update(['expires_at' => now()->subSecond()]);
    $revoked = createManageablePilotAccount('PILOT-REVOKED');
    $revoked->update(['revoked_at' => now()]);

    foreach (['PILOT-EXPIRED', 'PILOT-REVOKED', 'PILOT-UNKNOWN'] as $code) {
        expect(fn () => app(ResetPilotParticipantCredential::class)->handle($code))
            ->toThrow(RuntimeException::class);
    }

    config(['capstone_pilot.enabled' => false]);

    expect(fn () => app(ResetPilotParticipantCredential::class)->handle('PILOT-EXPIRED'))
        ->toThrow(RuntimeException::class);

    expect(Storage::disk('local')->allFiles('pilot/credentials'))->toBe([])
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('reset rolls back the password and tokens when the private file cannot be written', function (): void {
    $account = createManageablePilotAccount();
    $account->user->createToken('existing-device');
    $oldHash = $account->user->password;

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturn(false);
    $disk->shouldReceive('exists')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    expect(fn () => app(ResetPilotParticipantCredential::class)->handle('PILOT-0001'))
        ->toThrow(RuntimeException::class, 'Unable to write the private credential manifest.');

    expect($account->user()->firstOrFail()->password)->toBe($oldHash)
        ->and(PersonalAccessToken::query()->where('tokenable_id', $account->user_id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditEvent::ParticipantCredentialReset->value)->count())->toBe(0);
});

test('single revocation marks the account revoked and immediately deletes all tokens', function (): void {
    $account = createManageablePilotAccount();
    $account->user->createToken('first-device');
    $account->user->createToken('second-device');

    $result = app(RevokePilotParticipants::class)->handle(' pilot-0001 ');

    expect($result)->toMatchArray([
        'targeted_count' => 1,
        'revoked_count' => 1,
        'tokens_deleted' => 2,
    ])
        ->and($account->fresh()->revoked_at)->not->toBeNull()
        ->and(PersonalAccessToken::query()->where('tokenable_id', $account->user_id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditEvent::ParticipantAccessRevoked->value)->count())->toBe(1);
});

test('bulk revocation is idempotent and only audits state-changing work', function (): void {
    $accounts = collect([
        createManageablePilotAccount('PILOT-0001'),
        createManageablePilotAccount('PILOT-0002'),
        createManageablePilotAccount('PILOT-0003'),
    ]);
    $accounts->each(fn (PilotParticipantAccount $account) => $account->user->createToken('device'));

    config([
        'capstone_pilot.enabled' => false,
        'capstone_pilot.expires_at' => now()->subSecond(),
    ]);

    $first = app(RevokePilotParticipants::class)->handle(all: true);
    $second = app(RevokePilotParticipants::class)->handle(all: true);

    expect($first)->toMatchArray([
        'targeted_count' => 3,
        'revoked_count' => 3,
        'tokens_deleted' => 3,
    ])
        ->and($second)->toMatchArray([
            'targeted_count' => 3,
            'revoked_count' => 0,
            'tokens_deleted' => 0,
        ])
        ->and(PilotParticipantAccount::query()->whereNull('revoked_at')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditEvent::ParticipantAccessRevoked->value)->count())->toBe(3);
});

test('bulk revocation succeeds with no accounts and explicit command targeting is enforced', function (): void {
    expect(app(RevokePilotParticipants::class)->handle(all: true))->toMatchArray([
        'targeted_count' => 0,
        'revoked_count' => 0,
        'tokens_deleted' => 0,
    ]);

    $this->artisan('pilot:manage-access revoke')
        ->expectsOutputToContain('Specify one participant code or use --all')
        ->assertFailed();

    $this->artisan('pilot:manage-access revoke PILOT-0001 --all')
        ->expectsOutputToContain('not both')
        ->assertFailed();

    $this->artisan('pilot:manage-access reset --all')
        ->expectsOutputToContain('requires one participant code')
        ->assertFailed();
});

test('management command requires confirmation unless the explicit yes flag is supplied and redacts credentials', function (): void {
    $account = createManageablePilotAccount();

    $this->artisan('pilot:manage-access revoke --all')
        ->expectsConfirmation('Revoke access for all pilot participant accounts?', 'no')
        ->expectsOutputToContain('cancelled')
        ->assertFailed();

    $this->artisan('pilot:manage-access reset PILOT-0001 --yes')
        ->expectsOutputToContain('Private credential file written to the configured disk.')
        ->doesntExpectOutputToContain('PILOT-0001')
        ->doesntExpectOutputToContain('old-participant-password')
        ->assertSuccessful();

    expect($account->fresh()->revoked_at)->toBeNull()
        ->and(Storage::disk('local')->allFiles('pilot/credentials'))->toHaveCount(1);
});
