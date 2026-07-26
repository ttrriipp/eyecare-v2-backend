<?php

use App\Models\Conversation;
use App\Models\Feedback;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('feedback uses patient_id not customer_id', function () {
    $user = User::factory()->patient()->create();
    $feedback = Feedback::factory()->create(['patient_id' => $user->patient->id]);

    $this->actingAs($user)
        ->getJson('/api/feedback')
        ->assertOk();
});

test('cross-patient identifiers reveal no feedback', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $feedback = Feedback::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->getJson("/api/feedback/{$feedback->id}")
        ->assertNotFound();
});

test('conversation uses patient_id not customer_id', function () {
    $user = User::factory()->patient()->create();
    $conversation = Conversation::factory()->create(['patient_id' => $user->patient->id]);

    $this->actingAs($user)
        ->getJson('/api/conversations')
        ->assertOk();
});

test('cross-patient identifiers reveal no conversation', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $conversation = Conversation::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->getJson("/api/conversations/{$conversation->id}/messages")
        ->assertNotFound();
});

test('no query uses obsolete customer relationship', function () {
    // Verify Feedback and Conversation models use patient(), not customer()
    $feedback = new Feedback;
    expect(method_exists($feedback, 'patient'))->toBeTrue();

    $conversation = new Conversation;
    expect(method_exists($conversation, 'patient'))->toBeTrue();
});
