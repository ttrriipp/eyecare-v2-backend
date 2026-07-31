<?php

use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// --- Staff/Admin Authentication ---

test('staff can login via v1 and receive a token immediately', function () {
    $staff = User::factory()->staff()->create(['password' => bcrypt('password123')]);

    $response = $this->postJson('/api/v1/login', [
        'email' => $staff->email,
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user']]);
});

test('admin can login via v1 and receive a token immediately', function () {
    $admin = User::factory()->admin()->create(['password' => bcrypt('password123')]);

    $response = $this->postJson('/api/v1/login', [
        'email' => $admin->email,
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user']]);
});

test('staff login response includes staff role', function () {
    $staff = User::factory()->staff()->create(['password' => bcrypt('password123')]);

    $response = $this->postJson('/api/v1/login', [
        'email' => $staff->email,
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.role', 'staff');
});

test('patient login response includes patient role', function () {
    $user = User::factory()->patient()->create(['password' => bcrypt('password123')]);

    $response = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.role', 'patient');
});

// --- Patient Registration ---

test('current registration creates both user and patient', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Test Patient',
        'email' => 'test@example.com',
        'phone' => '09171234567',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['token', 'user']]);

    $user = User::where('email', 'test@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->role->name)->toBe('patient');
    expect($user->patient)->not->toBeNull();
    expect($user->patient->user_id)->toBe($user->id);
});

test('current registration returns a token immediately without OTP', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Test Patient',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated();

    $token = $response->json('data.token');
    expect($token)->not->toBeNull()
        ->and($token)->toContain('|');
});

// --- Clinical Route Access via patients.user_id ---

test('clinical routes are inaccessible without authentication', function () {
    $clinicalRoutes = [
        ['GET', '/api/v1/prescriptions'],
        ['GET', '/api/v1/quotations'],
        ['GET', '/api/v1/job-orders'],
        ['GET', '/api/v1/billing-records'],
        ['GET', '/api/v1/eyewear'],
        ['GET', '/api/v1/conversation'],
        ['GET', '/api/v1/frames'],
        ['GET', '/api/v1/frame-reservations'],
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
    $this->getJson('/api/v1/quotations')->assertOk();
    $this->getJson('/api/v1/job-orders')->assertOk();
    $this->getJson('/api/v1/billing-records')->assertOk();
    $this->getJson('/api/v1/eyewear')->assertOk();
    $this->getJson('/api/v1/frames')->assertOk();
    $this->getJson('/api/v1/frame-reservations')->assertOk();
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

test('one patient cannot link to multiple accounts', function () {
    $user = User::factory()->patient()->create();
    $patient = $user->patient;

    // The unique constraint on patients.user_id prevents this
    $secondUser = User::factory()->patient()->create();

    // Attempting to link the same patient to a second user should fail
    expect(fn () => $patient->update(['user_id' => $secondUser->id]))
        ->toThrow(QueryException::class);
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

test('register route still exists for backward compatibility', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    expect($routes)->toContain('api/v1/register');
});

test('login route still exists for backward compatibility', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    expect($routes)->toContain('api/v1/login');
});

test('appointment-types route is removed from patient contract', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    expect($routes)->not->toContain('api/v1/appointment-types');
});

test('intake routes are removed from patient contract', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    expect($routes)->not->toContain('api/v1/appointments/{appointment}/intake');
});
