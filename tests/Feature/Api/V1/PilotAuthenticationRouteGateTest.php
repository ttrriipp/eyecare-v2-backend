<?php

use App\Models\OtpChallenge;
use App\Models\PatientInvitation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['capstone_pilot.enabled' => false]);
});

test('pilot mode returns 404 for every participant-facing phone and invitation route without side effects', function (): void {
    $user = User::factory()->create();
    $initialUsers = User::query()->count();
    $initialChallenges = OtpChallenge::query()->count();
    $initialInvitations = PatientInvitation::query()->count();

    config(['capstone_pilot.enabled' => true]);

    $response = $this->postJson('/api/v1/patient-invitations/acceptance/otp', [
        'invitation_code' => 'missing',
    ]);
    expect($response->status(), 'Invitation OTP should be hidden in pilot mode')->toBe(404);

    $response = $this->postJson('/api/v1/patient-invitations/accept', [
        'invitation_code' => 'missing',
        'challenge_id' => 'missing',
        'code' => '123456',
    ]);
    expect($response->status(), 'Invitation acceptance should be hidden in pilot mode')->toBe(404);

    $publicRequests = [
        ['POST', '/api/v1/auth/registration/otp', ['contact_type' => 'phone', 'contact_value' => '09171234567']],
        ['POST', '/api/v1/auth/registration/verify', ['challenge_id' => 'missing', 'code' => '123456']],
        ['POST', '/api/v1/auth/register', ['registration_token' => 'missing']],
        ['POST', '/api/v1/auth/login', ['contact_value' => '09171234567', 'password' => 'password']],
        ['POST', '/api/v1/auth/login/verify', ['challenge_id' => 'missing', 'code' => '123456']],
        ['POST', '/api/v1/auth/password-recovery/otp', ['contact_value' => '09171234567']],
        ['POST', '/api/v1/auth/password-recovery/verify', ['challenge_id' => 'missing', 'code' => '123456']],
    ];

    foreach ($publicRequests as $index => [$method, $uri, $payload]) {
        $payload['email'] = "pilot-gate-{$index}@example.test";

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->json($method, $uri, $payload);

        expect($response->status(), "Unexpected status for {$method} {$uri}")->toBe(404);
    }

    $invitationRequests = [
        ['/api/v1/patient-invitations/acceptance/otp', ['invitation_code' => 'missing']],
        ['/api/v1/patient-invitations/accept', [
            'invitation_code' => 'missing',
            'challenge_id' => 'missing',
            'code' => '123456',
        ]],
    ];

    foreach ($invitationRequests as [$uri, $payload]) {
        $response = $this->actingAs($user)
            ->postJson($uri, $payload);

        expect($response->status(), "Unexpected status for {$uri}")->toBe(404);
    }

    expect(User::query()->count())->toBe($initialUsers)
        ->and(OtpChallenge::query()->count())->toBe($initialChallenges)
        ->and(PatientInvitation::query()->count())->toBe($initialInvitations);
});

test('non-pilot mode leaves the existing phone and invitation routes available', function (): void {
    $publicRequests = [
        '/api/v1/auth/registration/otp',
        '/api/v1/auth/registration/verify',
        '/api/v1/auth/register',
        '/api/v1/auth/login',
        '/api/v1/auth/login/verify',
        '/api/v1/auth/password-recovery/otp',
        '/api/v1/auth/password-recovery/verify',
    ];

    foreach ($publicRequests as $index => $uri) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($index + 30)])
            ->postJson($uri, ['email' => "non-pilot-gate-{$index}@example.test"])
            ->assertUnprocessable();
    }

    $user = User::factory()->create();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
        ->actingAs($user)
        ->postJson('/api/v1/patient-invitations/acceptance/otp', [])
        ->assertUnprocessable();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.41'])
        ->actingAs($user)
        ->postJson('/api/v1/patient-invitations/accept', [])
        ->assertUnprocessable();
});

test('pilot mode does not hide unrelated authenticated account routes', function (): void {
    config(['capstone_pilot.enabled' => true]);
    $user = User::factory()->create();

    $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson('/api/v1/me')
        ->assertOk();
});

test('the phone and invitation route inventory carries the pilot gate', function (): void {
    $gatedRoutes = [
        'api/v1/auth/registration/otp',
        'api/v1/auth/registration/verify',
        'api/v1/auth/register',
        'api/v1/auth/login',
        'api/v1/auth/login/verify',
        'api/v1/auth/password-recovery/otp',
        'api/v1/auth/password-recovery/verify',
        'api/v1/patient-invitations/acceptance/otp',
        'api/v1/patient-invitations/accept',
    ];

    foreach ($gatedRoutes as $uri) {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === $uri && in_array('POST', $route->methods(), true));

        expect($route)->not->toBeNull("Missing POST route: {$uri}")
            ->and($route->middleware())->toContain('reject.phone.auth');
    }
});
