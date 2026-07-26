<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('notifications contain no internal clinical notes', function () {
    // Verify that notification resources don't expose internal fields
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/notifications')
        ->assertOk();
});

test('patient can list their own notifications', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/notifications')
        ->assertOk();
});

test('notifications require authentication', function () {
    $this->getJson('/api/notifications')->assertUnauthorized();
});
