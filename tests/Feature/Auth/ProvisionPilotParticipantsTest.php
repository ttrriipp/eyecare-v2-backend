<?php

use App\Models\AuditLog;
use App\Models\Patient;
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
        'capstone_pilot.participant_limit' => 75,
        'capstone_pilot.credential_disk' => 'local',
    ]);
    Storage::fake('local');

    Hash::driver()->setRounds(4);
});

afterEach(function (): void {
    Hash::driver()->setRounds(12);
});

test('provisioning creates exactly the configured pseudonymous participants and one private credential manifest', function (): void {
    $this->artisan('pilot:provision-participants')
        ->expectsOutput('Provisioned 75 pilot participant account(s).')
        ->expectsOutputToContain('Private credential manifest written to the configured disk.')
        ->doesntExpectOutputToContain('PILOT-0001')
        ->assertSuccessful();

    $files = Storage::disk('local')->allFiles('pilot/credentials');
    expect($files)->toHaveCount(1);

    $manifestPath = $files[0];
    $manifest = json_decode(Storage::disk('local')->get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $accounts = PilotParticipantAccount::query()->with(['user', 'user.roles'])->get();

    expect($manifest['participants'])->toHaveCount(75)
        ->and($accounts)->toHaveCount(75)
        ->and(collect($manifest['participants'])->pluck('participant_code')->unique())->toHaveCount(75)
        ->and(collect($manifest['participants'])->pluck('password')->unique())->toHaveCount(75)
        ->and(Storage::disk('local')->getVisibility($manifestPath))->toBe('private');

    foreach ($accounts as $account) {
        $manifestParticipant = collect($manifest['participants'])
            ->firstWhere('participant_code', $account->participant_code);

        expect($manifestParticipant)->not->toBeNull()
            ->and(Hash::check($manifestParticipant['password'], $account->user->password))->toBeTrue()
            ->and($account->user->role_id)->toBe(Role::query()->where('name', Role::Patient)->value('id'))
            ->and($account->user->roles->pluck('name')->all())->toBe([Role::Patient])
            ->and($account->user->first_name)->toBeNull()
            ->and($account->user->middle_name)->toBeNull()
            ->and($account->user->last_name)->toBeNull()
            ->and($account->user->email)->toBeNull()
            ->and($account->user->phone)->toBeNull()
            ->and($account->user->address)->toBeNull()
            ->and($account->user->date_of_birth)->toBeNull()
            ->and($account->user->email_verified_at)->toBeNull()
            ->and($account->user->must_change_password)->toBeFalse()
            ->and($account->user->password_changed_at)->toBeNull()
            ->and(Patient::query()->where('user_id', $account->user_id)->exists())->toBeFalse()
            ->and($account->user->contacts()->exists())->toBeFalse();
    }

    expect(AuditLog::query()->whereIn('action', ['user.created', 'user.password_changed'])->count())
        ->toBe(75)
        ->and(PersonalAccessToken::query()->count())->toBe(0);

    $auditKeys = AuditLog::query()->get()
        ->flatMap(fn (AuditLog $entry): array => array_keys($entry->metadata ?? []));

    expect($auditKeys->intersect(['participant_code', 'password', 'device_name'])->isEmpty())->toBeTrue();
});

test('provisioning refuses a rerun without changing existing accounts or credentials', function (): void {
    config(['capstone_pilot.participant_limit' => 3]);

    $this->artisan('pilot:provision-participants')->assertSuccessful();
    $filesBefore = Storage::disk('local')->allFiles('pilot/credentials');
    $accountsBefore = PilotParticipantAccount::query()->count();
    $usersBefore = User::query()->count();

    $this->artisan('pilot:provision-participants')
        ->expectsOutputToContain('Pilot participant accounts already exist')
        ->assertFailed();

    expect(Storage::disk('local')->allFiles('pilot/credentials'))->toBe($filesBefore)
        ->and(PilotParticipantAccount::query()->count())->toBe($accountsBefore)
        ->and(User::query()->count())->toBe($usersBefore);
});

test('provisioning rejects public credential disks before creating accounts', function (): void {
    config([
        'capstone_pilot.participant_limit' => 3,
        'capstone_pilot.credential_disk' => 'public',
    ]);

    $this->artisan('pilot:provision-participants')
        ->expectsOutputToContain('must be configured as private')
        ->assertFailed();

    expect(PilotParticipantAccount::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});

test('provisioning rolls back users and accounts when the manifest cannot be written', function (): void {
    config(['capstone_pilot.participant_limit' => 3]);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('allFiles')->once()->with('pilot/credentials')->andReturn([]);
    $disk->shouldReceive('exists')->once()->andReturn(false);
    $disk->shouldReceive('put')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    $this->artisan('pilot:provision-participants')
        ->expectsOutputToContain('Unable to write the private credential manifest')
        ->assertFailed();

    expect(PilotParticipantAccount::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('provisioning requires an enabled pilot with a future expiry', function (): void {
    config(['capstone_pilot.enabled' => false]);

    $this->artisan('pilot:provision-participants')
        ->expectsOutputToContain('Pilot mode must be enabled')
        ->assertFailed();

    config([
        'capstone_pilot.enabled' => true,
        'capstone_pilot.expires_at' => now()->subSecond(),
    ]);

    $this->artisan('pilot:provision-participants')
        ->expectsOutputToContain('future expiry')
        ->assertFailed();
});
