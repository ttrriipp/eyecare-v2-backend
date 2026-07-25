<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('v1 routes are registered under /api/v1 prefix', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user);

    // Frame routes exist
    $this->getJson('/api/v1/frames')->assertOk();
});

test('old unversioned product routes still exist for backward compatibility', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user);

    // Old routes still work (will be removed in a later milestone)
    $this->getJson('/api/products')->assertOk();
});

test('v1 frame routes require authentication', function () {
    $this->getJson('/api/v1/frames')->assertUnauthorized();
    $this->getJson('/api/v1/frames/1')->assertUnauthorized();
});
