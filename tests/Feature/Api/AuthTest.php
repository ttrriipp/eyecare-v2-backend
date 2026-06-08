<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('customers can register and receive an api token', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Jane Customer',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'token',
                'user' => ['id', 'name', 'email', 'role'],
            ],
        ]);

    $this->assertDatabaseHas(User::class, [
        'email' => 'jane@example.com',
    ]);

    expect(User::query()->where('email', 'jane@example.com')->first()->role->name)
        ->toBe('customer');
});

test('customers can log in and receive an api token', function () {
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

test('authenticated customers can fetch their profile', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.role', 'customer');
});

test('authenticated customers can log out', function () {
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
        'name' => 'Another Customer',
        'email' => 'existing@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});
