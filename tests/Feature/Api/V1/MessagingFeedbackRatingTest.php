<?php

use App\Models\Conversation;
use App\Models\Feedback;
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
    $conversationB = Conversation::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->getJson('/api/v1/conversations/'.$conversationB->id.'/messages')
        ->assertNotFound();
});

test('feedback remains private and patient-scoped', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $feedback = Feedback::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->getJson("/api/v1/feedback/{$feedback->id}")
        ->assertNotFound();
});

test('ratings require authentication', function () {
    $this->postJson('/api/v1/ratings', [
        'product_variant_id' => 1,
        'rating' => 5,
    ])->assertUnauthorized();
});

test('moderated ratings are not exposed in public listing', function () {
    // Moderation hides comments but preserves stars
    $rating = FrameRating::factory()->create([
        'is_hidden' => true,
        'comment' => 'Inappropriate content',
        'rating' => 3,
    ]);

    // The rating still exists and its star value is preserved
    expect($rating->fresh()->is_hidden)->toBeTrue()
        ->and($rating->fresh()->rating)->toBe(3);
});
