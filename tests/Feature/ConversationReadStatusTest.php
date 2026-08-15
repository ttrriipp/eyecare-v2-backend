<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('unread for staff returns zero when watermark is null and no patient messages', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    expect($conversation->unreadForStaff())->toBe(0);
});

test('unread for staff counts all patient messages when watermark is null', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    Message::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);

    expect($conversation->unreadForStaff())->toBe(3);
});

test('unread for staff excludes staff messages', function () {
    $patient = User::factory()->patient()->create();
    $staff = User::factory()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    Message::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);
    Message::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $staff->id,
    ]);

    expect($conversation->unreadForStaff())->toBe(2);
});

test('unread for staff returns zero when all messages are before watermark', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    Message::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);

    $conversation->update(['staff_last_read_at' => now()->addMinute()]);

    expect($conversation->unreadForStaff())->toBe(0);
});

test('unread for staff counts only messages after watermark', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    // Old messages before watermark
    Message::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'created_at' => now()->subHour(),
    ]);

    $conversation->update(['staff_last_read_at' => now()->subMinutes(30)]);

    // New messages after watermark
    Message::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'created_at' => now(),
    ]);

    expect($conversation->unreadForStaff())->toBe(3);
});

test('unread for patient counts messages not sent by the account', function () {
    $patient = User::factory()->patient()->create();
    $staff = User::factory()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    Message::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $staff->id,
    ]);
    Message::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);

    expect($conversation->unreadForPatient($patient))->toBe(2);
});

test('unread for patient returns zero when all are read', function () {
    $patient = User::factory()->patient()->create();
    $staff = User::factory()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    Message::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $staff->id,
        'read_at' => now(),
    ]);

    expect($conversation->unreadForPatient($patient))->toBe(0);
});

test('scope with unread for staff loads counts in one query', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    Message::factory()->count(5)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);

    $conversations = Conversation::withUnreadForStaff()->get();

    expect($conversations->first()->unread_for_staff)->toBe(5);
});

test('scope with unread for staff respects watermark', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
        'staff_last_read_at' => now()->subMinutes(10),
    ]);

    // Old messages
    Message::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'created_at' => now()->subHour(),
    ]);

    // New messages after watermark
    Message::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'created_at' => now(),
    ]);

    $conversations = Conversation::withUnreadForStaff()->get();

    expect($conversations->first()->unread_for_staff)->toBe(2);
});

test('scope with unread for staff returns zero for thread where only staff has spoken', function () {
    $patient = User::factory()->patient()->create();
    $staff = User::factory()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    Message::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $staff->id,
    ]);

    $conversations = Conversation::withUnreadForStaff()->get();

    expect($conversations->first()->unread_for_staff)->toBe(0);
});

test('scope with unread for staff handles empty thread', function () {
    $patient = User::factory()->patient()->create();
    Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $conversations = Conversation::withUnreadForStaff()->get();

    expect($conversations->first()->unread_for_staff)->toBe(0);
});
