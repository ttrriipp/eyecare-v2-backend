<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('customer can update their name', function () {
    $user = User::factory()->customer()->create();

    $response = $this->actingAs($user)->patchJson('/api/user', ['name' => 'New Name']);

    $response->assertOk()
        ->assertJsonPath('data.name', 'New Name');

    expect($user->fresh()->name)->toBe('New Name');
});

test('customer can update their email', function () {
    $user = User::factory()->customer()->create();

    $response = $this->actingAs($user)->patchJson('/api/user', ['email' => 'new@email.com']);

    $response->assertOk()
        ->assertJsonPath('data.email', 'new@email.com');
});

test('customer can update their phone', function () {
    $user = User::factory()->customer()->create();

    $response = $this->actingAs($user)->patchJson('/api/user', ['phone' => '09171234567']);

    $response->assertOk();
    expect($user->fresh()->phone)->toBe('09171234567');
});

test('email must be unique excluding self', function () {
    $other = User::factory()->customer()->create(['email' => 'taken@email.com']);
    $user = User::factory()->customer()->create();

    $this->actingAs($user)
        ->patchJson('/api/user', ['email' => 'taken@email.com'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('customer can update with their own current email', function () {
    $user = User::factory()->customer()->create(['email' => 'mine@email.com']);

    $this->actingAs($user)
        ->patchJson('/api/user', ['email' => 'mine@email.com'])
        ->assertOk();
});

test('at least one field is required', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user)
        ->patchJson('/api/user', [])
        ->assertUnprocessable();
});

test('unauthenticated request returns 401', function () {
    $this->patchJson('/api/user', ['name' => 'Test'])
        ->assertUnauthorized();
});
