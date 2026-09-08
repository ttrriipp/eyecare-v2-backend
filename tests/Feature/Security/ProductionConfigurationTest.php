<?php

use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('production panel does not configure MFA', function () {
    $environment = app()->environment();
    app()->instance('env', 'production');

    try {
        $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

        expect($panel->getMultiFactorAuthenticationProviders())->toBeEmpty()
            ->and($panel->isMultiFactorAuthenticationRequired())->toBeFalse();
    } finally {
        app()->instance('env', $environment);
    }
});

test('patient role cannot access admin panel', function () {
    $patient = User::factory()->patient()->create();
    $this->actingAs($patient);
    $this->get('/admin')->assertForbidden();
});

test('staff and admin can access admin panel', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();

    $this->actingAs($admin);
    $this->get('/admin')->assertSuccessful();

    $this->actingAs($staff);
    $this->get('/admin')->assertSuccessful();
});

test('privacy notice fields exist on users table', function () {
    $user = User::factory()->create([
        'privacy_notice_version' => '1.0',
        'privacy_acknowledged_at' => now(),
    ]);

    expect($user->privacy_notice_version)->toBe('1.0')
        ->and($user->privacy_acknowledged_at)->not->toBeNull();
});

test('migrate fresh seed succeeds', function () {
    // Verify the canonical rebuild works
    $this->seed(DatabaseSeeder::class);
    expect(true)->toBeTrue();
});
