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

    expect($v1Routes)->toContain('api/v1/auth/register')
        ->and($v1Routes)->toContain('api/v1/auth/login')
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

test('patient can view profile via v1/me', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.linked_patient.contact_email', $user->patient->contact_email);
});

test('missing patient linkage has consistent error', function () {
    // A user without a patient record accessing patient-specific endpoints
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff)
        ->getJson('/api/v1/patient/profile')
        ->assertNotFound();
});
