<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

test('a token older than 30 days returns 401', function () {
    $user = User::factory()->customer()->create();
    $token = $user->createToken('test');

    // Age the token to 31 days
    PersonalAccessToken::query()
        ->where('id', $token->accessToken->id)
        ->update(['created_at' => now()->subDays(31)]);

    $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
        ->getJson('/api/user')
        ->assertUnauthorized();
});

test('a fresh token authenticates successfully', function () {
    $user = User::factory()->customer()->create();
    $token = $user->createToken('test');

    $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
        ->getJson('/api/user')
        ->assertOk();
});
