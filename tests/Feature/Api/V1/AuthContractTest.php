<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('v1 auth routes are registered', function () {
    $v1Routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri, 'api/v1'))
        ->pluck('uri')
        ->toArray();

    expect($v1Routes)->toContain('api/v1/register')
        ->and($v1Routes)->toContain('api/v1/login')
        ->and($v1Routes)->toContain('api/v1/logout')
        ->and($v1Routes)->toContain('api/v1/me');
});

test('unversioned auth routes are absent', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    // Old unversioned auth routes should not exist
    expect($routes)->not->toContain('api/register')
        ->and($routes)->not->toContain('api/login')
        ->and($routes)->not->toContain('api/user');
});

test('patient can register via v1', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Test Patient',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['token', 'user']]);
});

test('patient can login via v1', function () {
    $user = User::factory()->patient()->create(['password' => bcrypt('password123')]);

    $response = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user']]);
});

test('patient can view profile via v1/me', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email);
});

test('missing patient linkage has consistent error', function () {
    // A user without a patient record accessing patient-specific endpoints
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff)
        ->getJson('/api/v1/patient/profile')
        ->assertNotFound();
});
