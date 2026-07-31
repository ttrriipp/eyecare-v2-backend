<?php

use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
});

// --- Intake routes are retired ---

test('intake GET route is removed from patient contract', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    expect($routes)->not->toContain('api/v1/appointments/{appointment}/intake');
});

test('intake PUT route is removed from patient contract', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    expect($routes)->not->toContain('api/v1/appointments/{appointment}/intake');
});

test('intake submit route is removed from patient contract', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    expect($routes)->not->toContain('api/v1/appointments/{appointment}/intake/submit');
});

test('intake endpoints return 404 for authenticated users', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/1/intake')
        ->assertNotFound();

    $this->actingAs($user)
        ->putJson('/api/v1/appointments/1/intake', ['chief_complaint' => 'test'])
        ->assertNotFound();

    $this->actingAs($user)
        ->postJson('/api/v1/appointments/1/intake/submit')
        ->assertNotFound();
});
