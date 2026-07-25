<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('patients can register and receive an api token', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Jane Patient',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'token',
                'user' => ['id', 'patient_number', 'name', 'email', 'phone', 'role'],
            ],
        ]);

    $user = User::query()->where('email', 'jane@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->role->name)->toBe('patient');

    $this->assertDatabaseHas('patients', [
        'user_id' => $user->id,
        'full_name' => 'Jane Patient',
    ]);
});

test('patients can log in and receive an api token', function () {
    User::factory()->customer()->create([
        'email' => 'login@example.com',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'login@example.com',
        'password' => 'password123',
    ]);

    $response->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'token',
                'user' => ['id', 'name', 'email', 'role'],
            ],
        ]);
});

test('authenticated patients can fetch their profile', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.role', 'patient');
});

test('authenticated patients can log out', function () {
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/logout')
        ->assertSuccessful();

    expect($user->tokens()->count())->toBe(0);
});

test('login rejects invalid credentials', function () {
    User::factory()->customer()->create([
        'email' => 'login@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/login', [
        'email' => 'login@example.com',
        'password' => 'wrong-password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('registration rejects duplicate email', function () {
    User::factory()->customer()->create([
        'email' => 'existing@example.com',
    ]);

    $this->postJson('/api/register', [
        'name' => 'Another Patient',
        'email' => 'existing@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});
