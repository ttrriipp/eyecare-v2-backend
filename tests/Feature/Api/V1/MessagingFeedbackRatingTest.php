<?php

use App\Models\Conversation;
use App\Models\FrameRating;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('conversation access is patient-scoped', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    Conversation::factory()->create(['patient_id' => $userB->patient->id]);

    // userA has their own conversation, not userB's
    $this->actingAs($userA)
        ->getJson('/api/v1/conversation/messages')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('ratings require authentication', function () {
    $this->postJson('/api/v1/job-order-items/1/rating', [
        'product_variant_id' => 1,
        'rating' => 5,
    ])->assertUnauthorized();
});

test('moderated ratings are not exposed in public listing', function () {
    $rating = FrameRating::factory()->create([
        'is_hidden' => true,
        'comment' => 'Inappropriate content',
        'rating' => 3,
    ]);

    expect($rating->fresh()->is_hidden)->toBeTrue()
        ->and($rating->fresh()->rating)->toBe(3);
});
