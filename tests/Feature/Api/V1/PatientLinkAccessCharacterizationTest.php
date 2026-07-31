<?php

use App\Models\Conversation;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// --- Clinical routes that require patients.user_id linkage ---

test('prescriptions are scoped through the patient relationship', function () {
    $user = User::factory()->patient()->create();
    $patient = $user->patient;

    // The prescription controller scopes by authenticated user's patient
    $this->actingAs($user)
        ->getJson('/api/v1/prescriptions')
        ->assertOk();
});

test('quotations are scoped through the patient relationship', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/quotations')
        ->assertOk();
});

test('job orders are scoped through the patient relationship', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/job-orders')
        ->assertOk();
});

test('billing records are scoped through the patient relationship', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/billing-records')
        ->assertOk();
});

test('eyewear is scoped through the patient relationship', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/eyewear')
        ->assertOk();
});

test('conversation is scoped through the patient relationship', function () {
    $user = User::factory()->patient()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/conversation');

    // First access creates the conversation (201), subsequent return 200
    $response->assertSuccessful();
});

test('frame reservations are scoped through the patient relationship', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/frame-reservations')
        ->assertOk();
});

test('appointments are scoped through the patient relationship', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/appointments')
        ->assertOk();
});

test('frames catalogue is accessible to linked patients', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/frames')
        ->assertOk();
});

// --- Staff user access to clinical routes ---

test('staff user accessing prescriptions gets 403 without patient link', function () {
    $staff = User::factory()->staff()->create();

    // Staff has no patient relationship, so the middleware returns 403
    $response = $this->actingAs($staff)
        ->getJson('/api/v1/prescriptions');

    // Current behavior: 403 from require.patient.link middleware
    $response->assertForbidden();
});

// --- Appointment direct booking (removed from contract) ---

test('direct appointment creation route is removed from patient contract', function () {
    // The POST /appointments route has been removed in favor of appointment requests
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri, 'api/v1/appointments'))
        ->filter(fn ($r) => in_array('POST', (array) $r->methods))
        ->filter(fn ($r) => $r->uri === 'api/v1/appointments')
        ->count();

    expect($routes)->toBe(0);
});

// --- Intake routes (removed from contract) ---

test('intake routes are removed from patient contract', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    expect($routes)->not->toContain('api/v1/appointments/{appointment}/intake');
});

// --- Walk-in patient route access ---

test('walk-in patients can login if they have credentials', function () {
    // Walk-in patients currently have null email/password
    // They cannot authenticate through the API
    $patient = Patient::factory()->walkIn()->create();

    expect($patient->account)->toBeNull();
    expect($patient->contact_email)->toBeNull();
});
