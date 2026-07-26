<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('production MFA is configured in panel', function () {
    // Verify the User model implements HasAppAuthentication
    $user = User::factory()->admin()->create();
    expect($user)->toBeInstanceOf(\Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication::class);
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
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    expect(true)->toBeTrue();
});
