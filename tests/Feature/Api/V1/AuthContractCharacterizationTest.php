<?php

use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// --- Clinical Route Access via patients.user_id ---

test('clinical routes are inaccessible without authentication', function () {
    $clinicalRoutes = [
        ['GET', '/api/v1/prescriptions'],
        ['GET', '/api/v1/optical-orders'],
        ['GET', '/api/v1/conversation'],
        ['GET', '/api/v1/frames'],
        ['GET', '/api/v1/saved-frames'],
        ['GET', '/api/v1/appointments'],
    ];

    foreach ($clinicalRoutes as [$method, $uri]) {
        $this->json($method, $uri)->assertUnauthorized();
    }
});

test('linked patient can access clinical routes through patient relationship', function () {
    $user = User::factory()->patient()->create();
    $patient = $user->patient;

    $this->actingAs($user);

    // These routes scope data through the authenticated user's patient
    $this->getJson('/api/v1/prescriptions')->assertOk();
    $this->getJson('/api/v1/optical-orders')->assertOk();
    $this->getJson('/api/v1/frames')->assertOk();
    $this->getJson('/api/v1/saved-frames')->assertOk();
    $this->getJson('/api/v1/appointments')->assertOk();
});

test('staff can access the me endpoint', function () {
    $staff = User::factory()->staff()->create();

    $response = $this->actingAs($staff)
        ->getJson('/api/v1/me');

    $response->assertSuccessful();
});

test('staff user has no patient relationship by default', function () {
    $staff = User::factory()->staff()->create();

    expect($staff->patient)->toBeNull();
});

test('patient user has a linked patient record', function () {
    $user = User::factory()->patient()->create();

    expect($user->patient)->not->toBeNull();
    expect($user->patient->user_id)->toBe($user->id);
});

// --- Patient Model Link Behavior ---

test('patients.user_id is the authoritative active link', function () {
    $user = User::factory()->patient()->create();
    $patient = $user->patient;

    expect($patient->user_id)->toBe($user->id)
        ->and($patient->account->id)->toBe($user->id);
});

test('patient link ownership cannot be changed through mass assignment', function () {
    $user = User::factory()->patient()->create();
    $patient = $user->patient;

    // A second account cannot take over the existing link through a generic
    // model update; link ownership is server-controlled.
    $secondUser = User::factory()->patient()->create();

    $patient->update(['user_id' => $secondUser->id]);

    expect($patient->fresh()->user_id)->toBe($user->id);
});

test('deleting the account preserves but unlinks the patient', function () {
    $user = User::factory()->patient()->create();
    $patientId = $user->patient->id;

    $user->delete();

    $patient = Patient::withTrashed()->find($patientId);
    expect($patient)->not->toBeNull();
    expect($patient->user_id)->toBeNull();
});

// --- Walk-in Patient Behavior ---

test('walk-in patients have no account', function () {
    $patient = Patient::factory()->walkIn()->create();

    expect($patient->account)->toBeNull();
    expect($patient->user_id)->toBeNull();
    expect($patient->contact_email)->toBeNull();
});

// --- Route Contract (updated for new API) ---

test('v1 route count reflects new contract', function () {
    $v1Routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri, 'api/v1'))
        ->count();

    // New contract has more routes than the old 35
    expect($v1Routes)->toBeGreaterThan(35);
});

test('appointment-types route is available in the patient contract', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    expect($routes)->toContain('api/v1/appointment-types');
});

test('intake routes are removed from patient contract', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    expect($routes)->not->toContain('api/v1/appointments/{appointment}/intake');
});
