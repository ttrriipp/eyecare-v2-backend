<?php

use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('patient can submit a frame rating', function () {
    $user = User::factory()->patient()->create();
    $variant = ProductVariant::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/job-order-items/1/rating', [
            'product_variant_id' => $variant->id,
            'rating' => 5,
            'comment' => 'Excellent frame!',
        ])
        ->assertCreated()
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.comment', 'Excellent frame!');
});

test('rating requires valid variant', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/job-order-items/1/rating', [
            'product_variant_id' => 9999,
            'rating' => 5,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['product_variant_id']);
});

test('rating must be between 1 and 5', function () {
    $user = User::factory()->patient()->create();
    $variant = ProductVariant::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/job-order-items/1/rating', [
            'product_variant_id' => $variant->id,
            'rating' => 6,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rating']);
});

test('rating requires authentication', function () {
    $this->postJson('/api/v1/job-order-items/1/rating', [])->assertUnauthorized();
});
