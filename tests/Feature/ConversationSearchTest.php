<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

test('search returns matching messages', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'body' => 'I need a new frame for my prescription',
    ]);

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'body' => 'When will my glasses be ready?',
    ]);

    $this->actingAs($patient)
        ->getJson('/api/v1/conversation/messages/search?q=prescription')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertSee('prescription');
});

test('search scopes to own conversation only', function () {
    $patient1 = User::factory()->patient()->create();
    $patient2 = User::factory()->patient()->create();

    Conversation::query()->create([
        'account_user_id' => $patient1->id,
        'patient_id' => $patient1->patient->id,
    ]);
    $conversation2 = Conversation::query()->create([
        'account_user_id' => $patient2->id,
        'patient_id' => $patient2->patient->id,
    ]);

    Message::factory()->create([
        'conversation_id' => $conversation2->id,
        'sender_id' => $patient2->id,
        'body' => 'unique secret keyword xyz',
    ]);

    $this->actingAs($patient1)
        ->getJson('/api/v1/conversation/messages/search?q=unique+secret+keyword+xyz')
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
});

test('search rejects empty query', function () {
    $patient = User::factory()->patient()->create();
    Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($patient)
        ->getJson('/api/v1/conversation/messages/search?q=')
        ->assertUnprocessable();
});
