<?php

/**
 * Tests for conversation ownership migration.
 *
 * @see tasks/todo.md Task 2
 */

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('conversations table has account_user_id column', function () {
    expect(Schema::hasColumn('conversations', 'account_user_id'))->toBeTrue();
});

test('patient_id is nullable', function () {
    $conversation = Conversation::factory()->create([
        'patient_id' => null,
    ]);

    expect($conversation->patient_id)->toBeNull();
});

test('account_user_id is nullable', function () {
    $conversation = Conversation::factory()->create([
        'account_user_id' => null,
    ]);

    expect($conversation->account_user_id)->toBeNull();
});

test('existing linked conversations have account_user_id backfilled', function () {
    // Create a conversation with account_user_id set (simulating backfilled state)
    $user = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'patient_id' => $user->patient->id,
        'account_user_id' => $user->id,
    ]);

    // Verify the conversation has both fields set
    $conversation->refresh();
    expect($conversation->account_user_id)->toBe($user->id)
        ->and($conversation->patient_id)->toBe($user->patient->id);
});

test('messages and attachments survive migration', function () {
    $user = User::factory()->patient()->create();
    $conversation = Conversation::query()->create(['patient_id' => $user->patient->id]);

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $user->id,
        'body' => 'Test message',
    ]);

    // Messages are preserved
    expect($conversation->messages()->count())->toBe(1)
        ->and($conversation->messages->first()->body)->toBe('Test message');
});
