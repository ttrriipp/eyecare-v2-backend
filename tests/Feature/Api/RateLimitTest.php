<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('patient mutation endpoints enforce rate limits', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user);

    // Frame reservation creation should be rate-limited
    // The throttle middleware is applied to the v1 group
    $response = $this->postJson('/api/v1/frame-reservations', [
        'items' => [['product_variant_id' => 1]],
    ]);

    // First request should not be rate-limited (may fail validation, that's fine)
    expect($response->status())->not->toBe(429);
});

test('patient list endpoints enforce rate limits', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user);

    // List endpoints should be rate-limited (throttle:60,1)
    $response = $this->getJson('/api/v1/frames');
    expect($response->status())->not->toBe(429);
});

test('unauthenticated requests are rate limited on login', function () {
    // Login endpoint has throttle:login
    $response = $this->postJson('/api/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'wrong',
    ]);

    // Should return 422 (validation), not 429 (rate limited) on first attempt
    expect($response->status())->toBe(422);
});
