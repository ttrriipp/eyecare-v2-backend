<?php

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\PilotParticipantAccount;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config([
        'capstone_pilot.enabled' => true,
        'capstone_pilot.expires_at' => now()->addDays(7),
        'capstone_pilot.rate_limits.ip_per_minute' => 10,
        'capstone_pilot.rate_limits.code_per_minute' => 5,
    ]);
});

function createPilotLoginAccount(string $code = 'PILOT-0001', string $password = 'participant-secret'): PilotParticipantAccount
{
    $patientRole = Role::query()->where('name', Role::Patient)->firstOrFail();
    $user = User::factory()->create([
        'email' => null,
        'phone' => null,
        'password' => Hash::make($password),
        'role_id' => $patientRole->id,
    ]);
    $user->roles()->sync([$patientRole->id]);

    return PilotParticipantAccount::factory()->for($user)->create([
        'participant_code' => $code,
        'expires_at' => now()->addDays(3),
    ]);
}

test('participant login is unavailable while the pilot is disabled', function (): void {
    config(['capstone_pilot.enabled' => false]);

    $response = $this->postJson('/api/v1/auth/participant-login', [
        'participant_code' => 'PILOT-0001',
        'password' => 'participant-secret',
    ]);

    $response->assertNotFound();
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('participant login returns the direct token shape and token works on an allowed route', function (): void {
    $account = createPilotLoginAccount();
    $pilotEndsAt = now()->addDays(2)->startOfSecond();
    config(['capstone_pilot.expires_at' => $pilotEndsAt]);

    $response = $this->postJson('/api/v1/auth/participant-login', [
        'participant_code' => '  pilot-0001 ',
        'password' => 'participant-secret',
        'device_name' => 'Android test device',
        'installation_id' => 'installation-0001',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'step_up_required',
                'token',
                'user' => ['id', 'name', 'role', 'link_status'],
            ],
        ])
        ->assertJsonPath('data.step_up_required', false)
        ->assertJsonMissingPath('data.user.participant_code')
        ->assertJsonMissingPath('data.user.password');

    $token = $response->json('data.token');
    $personalAccessToken = PersonalAccessToken::query()
        ->where('tokenable_id', $account->user_id)
        ->firstOrFail();

    expect($personalAccessToken->expires_at->equalTo($pilotEndsAt))->toBeTrue()
        ->and($personalAccessToken->installation_id)->toBe('installation-0001');

    $this->withToken($token)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $account->user_id);
});

test('participant login writes only redacted authentication audit metadata', function (): void {
    $account = createPilotLoginAccount();

    $this->postJson('/api/v1/auth/participant-login', [
        'participant_code' => $account->participant_code,
        'password' => 'participant-secret',
        'device_name' => 'Android test device',
        'installation_id' => 'installation-0001',
    ])->assertOk();

    $entry = AuditLog::query()
        ->where('action', AuditEvent::ParticipantLoggedIn->value)
        ->where('subject_id', $account->user_id)
        ->firstOrFail();

    expect($entry->actor_id)->toBe($account->user_id)
        ->and($entry->metadata)->toMatchArray([
            'auth_method' => 'participant_code',
            'installation_bound' => true,
        ])
        ->and($entry->metadata)->not->toHaveKeys([
            'participant_code',
            'password',
            'device_name',
            'installation_id',
        ])
        ->and($entry->ip_address)->not->toBeNull();
});

test('unknown, wrong-password, expired, revoked, and wrong-role logins share one generic response', function (): void {
    createPilotLoginAccount('PILOT-VALID');
    $expired = createPilotLoginAccount('PILOT-EXPIRED');
    $expired->update(['expires_at' => now()->subSecond()]);
    $revoked = createPilotLoginAccount('PILOT-REVOKED');
    $revoked->update(['revoked_at' => now()]);

    $staffRole = Role::query()->where('name', Role::Staff)->firstOrFail();
    $staff = User::factory()->staff()->create([
        'email' => null,
        'phone' => null,
        'password' => Hash::make('participant-secret'),
    ]);
    $staffAccount = PilotParticipantAccount::factory()->for($staff)->create([
        'participant_code' => 'PILOT-STAFF',
    ]);
    expect($staff->fresh()->role_id)->toBe($staffRole->id);

    $responses = collect([
        ['participant_code' => 'PILOT-UNKNOWN', 'password' => 'participant-secret'],
        ['participant_code' => 'PILOT-VALID', 'password' => 'wrong-password'],
        ['participant_code' => $expired->participant_code, 'password' => 'participant-secret'],
        ['participant_code' => $revoked->participant_code, 'password' => 'participant-secret'],
        ['participant_code' => $staffAccount->participant_code, 'password' => 'participant-secret'],
    ])->map(fn (array $payload) => $this->postJson('/api/v1/auth/participant-login', $payload));

    $responses->each(fn ($response) => $response->assertUnprocessable());

    expect($responses->map(fn ($response) => $response->json())->unique()->count())->toBe(1)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('participant login validates only its documented fields while enabled', function (): void {
    $response = $this->postJson('/api/v1/auth/participant-login', [
        'participant_code' => 'PILOT-0001',
        'password' => 'participant-secret',
        'email' => 'not-accepted@example.com',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('participant login applies independent IP and normalized-code limits', function (): void {
    config([
        'capstone_pilot.rate_limits.ip_per_minute' => 2,
        'capstone_pilot.rate_limits.code_per_minute' => 2,
    ]);

    $ipPayloads = collect(['PILOT-IP-A', 'PILOT-IP-B', 'PILOT-IP-C'])
        ->map(fn (string $code): array => [
            'participant_code' => $code,
            'password' => 'wrong-password',
        ]);
    $ipResponses = $ipPayloads->map(fn (array $payload) => $this
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->postJson('/api/v1/auth/participant-login', $payload));

    $ipResponses->take(2)->each(fn ($response) => $response->assertUnprocessable());
    $ipResponses->last()->assertStatus(429)->assertJsonPath('error.code', 'API_RATE_LIMIT_REACHED');

    RateLimiter::clear('participant-login:ip:198.51.100.20');

    $account = createPilotLoginAccount('PILOT-CODE');
    $codeResponses = collect(['198.51.100.10', '198.51.100.11', '198.51.100.12'])
        ->map(fn (string $ip) => $this
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/participant-login', [
                'participant_code' => strtolower($account->participant_code),
                'password' => 'participant-secret',
            ]));

    $codeResponses->take(2)->each(fn ($response) => $response->assertOk());
    $codeResponses->last()->assertStatus(429)->assertJsonPath('error.code', 'API_RATE_LIMIT_REACHED');
});

test('participant login route is named and uses the dedicated limiter', function (): void {
    $route = Route::getRoutes()->getByName('api.v1.auth.participant-login');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('api/v1/auth/participant-login')
        ->and($route->methods())->toContain('POST')
        ->and($route->middleware())->toContain('throttle:participant-login');
});
