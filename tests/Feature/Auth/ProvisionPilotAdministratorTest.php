<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Database\Seeders\RoleSeeder;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('administrator bootstrap accepts a hidden password and leaves MFA enrollment pending', function (): void {
    $this->artisan('pilot:provision-administrator admin@example.test Study Operator --yes')
        ->expectsQuestion('Administrator password', 'SecureAdmin123!')
        ->expectsQuestion('Confirm administrator password', 'SecureAdmin123!')
        ->doesntExpectOutputToContain('SecureAdmin123!')
        ->assertSuccessful();

    $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

    expect(Hash::check('SecureAdmin123!', $admin->password))->toBeTrue()
        ->and(Hash::check('password', $admin->password))->toBeFalse()
        ->and($admin->first_name)->toBe('Study')
        ->and($admin->last_name)->toBe('Operator')
        ->and($admin->role_id)->toBe(Role::query()->where('name', Role::Admin)->value('id'))
        ->and($admin->roles->pluck('name')->all())->toBe([Role::Admin])
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->is_optometrist)->toBeFalse()
        ->and($admin->must_change_password)->toBeFalse()
        ->and($admin->password_changed_at)->toBeNull()
        ->and($admin->app_authentication_secret)->toBeNull()
        ->and(AuditLog::query()->where('subject_id', $admin->id)->get()->flatMap(
            fn (AuditLog $entry): array => array_keys($entry->metadata ?? []),
        )->intersect(['password', 'password_confirmation'])->isEmpty())->toBeTrue();

    $environment = app()->environment();
    app()->instance('env', 'production');

    try {
        $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

        expect($panel->getMultiFactorAuthenticationProviders())->toHaveKey('app')
            ->and($panel->isMultiFactorAuthenticationRequired())->toBeTrue();
    } finally {
        app()->instance('env', $environment);
    }
});

test('administrator bootstrap updates an existing admin without changing MFA enrollment state', function (): void {
    $admin = User::factory()->admin()->create([
        'email' => 'admin@example.test',
        'password' => Hash::make('OldAdmin123!'),
        'first_name' => 'Old',
        'last_name' => 'Name',
        'app_authentication_secret' => 'existing-mfa-secret',
    ]);

    $passwordFile = tempnam(sys_get_temp_dir(), 'pilot-admin-password-');
    file_put_contents($passwordFile, "NewAdmin123!\n");

    try {
        $this->artisan("pilot:provision-administrator admin@example.test Updated Operator --password-file={$passwordFile} --yes")
            ->doesntExpectOutputToContain('NewAdmin123!')
            ->assertSuccessful();
    } finally {
        unlink($passwordFile);
    }

    $updated = $admin->fresh();

    expect(User::query()->where('email', 'admin@example.test')->count())->toBe(1)
        ->and($updated->first_name)->toBe('Updated')
        ->and($updated->last_name)->toBe('Operator')
        ->and(Hash::check('NewAdmin123!', $updated->password))->toBeTrue()
        ->and($updated->app_authentication_secret)->toBe('existing-mfa-secret')
        ->and($updated->must_change_password)->toBeFalse()
        ->and($updated->password_changed_at)->not->toBeNull()
        ->and($updated->roles->pluck('name')->all())->toBe([Role::Admin]);
});

test('administrator bootstrap refuses to promote a non-admin account', function (): void {
    $patient = User::factory()->create([
        'email' => 'admin@example.test',
        'password' => Hash::make('Existing123!'),
        'role_id' => Role::query()->where('name', Role::Patient)->value('id'),
    ]);
    $patient->roles()->sync([Role::query()->where('name', Role::Patient)->value('id')]);

    $this->artisan('pilot:provision-administrator admin@example.test Study Operator --yes')
        ->expectsQuestion('Administrator password', 'SecureAdmin123!')
        ->expectsQuestion('Confirm administrator password', 'SecureAdmin123!')
        ->expectsOutputToContain('Refusing to promote a non-admin account')
        ->assertFailed();

    expect($patient->fresh()->roles->pluck('name')->all())->toBe([Role::Patient])
        ->and(Hash::check('Existing123!', $patient->fresh()->password))->toBeTrue();
});

test('noninteractive administrator bootstrap requires a protected password file', function (): void {
    $this->artisan('pilot:provision-administrator admin@example.test Study Operator --yes --no-interaction')
        ->expectsOutputToContain('--password-file')
        ->assertFailed();

    expect(User::query()->where('email', 'admin@example.test')->exists())->toBeFalse();
});

test('administrator bootstrap rejects a password file readable by other users', function (): void {
    $passwordFile = tempnam(sys_get_temp_dir(), 'pilot-admin-password-');
    file_put_contents($passwordFile, "SecureAdmin123!\n");
    chmod($passwordFile, 0644);

    try {
        $this->artisan("pilot:provision-administrator admin@example.test Study Operator --password-file={$passwordFile} --yes --no-interaction")
            ->expectsOutputToContain('readable only by its owner')
            ->assertFailed();
    } finally {
        unlink($passwordFile);
    }

    expect(User::query()->where('email', 'admin@example.test')->exists())->toBeFalse();
});
