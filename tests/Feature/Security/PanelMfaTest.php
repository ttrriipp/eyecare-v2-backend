<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('user model does not implement Filament app authentication', function () {
    $user = User::factory()->admin()->create();

    expect($user)->not->toBeInstanceOf(HasAppAuthentication::class);
});

test('users table no longer stores Filament app authentication secrets', function () {
    expect(Schema::hasColumn('users', 'app_authentication_secret'))->toBeFalse();
});

test('staff and admin can access panel without MFA in testing', function () {
    // In non-production (testing), MFA is not required
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();

    $this->actingAs($admin);
    $this->get('/admin')->assertSuccessful();

    $this->actingAs($staff);
    $this->get('/admin')->assertSuccessful();
});

test('patient cannot access panel regardless of MFA', function () {
    $patient = User::factory()->patient()->create();

    $this->actingAs($patient);
    $this->get('/admin')->assertForbidden();
});
