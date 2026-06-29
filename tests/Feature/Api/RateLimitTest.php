<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('login endpoint is rate limited', function () {
    $payload = ['email' => 'nobody@example.com', 'password' => 'wrong'];

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', $payload);
    }

    $this->postJson('/api/login', $payload)
        ->assertStatus(429);
});

test('general api is rate limited at 60 requests per minute', function () {
    $user = User::factory()->customer()->create();
    $token = $user->createToken('test')->plainTextToken;

    for ($i = 0; $i < 60; $i++) {
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user');
    }

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/user')
        ->assertStatus(429);
});
