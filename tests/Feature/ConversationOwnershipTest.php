<?php

/**
 * Tests for conversation ownership states.
 *
 * @see tasks/todo.md Task 3
 */

use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('conversation exposes account relationship', function () {
    $user = User::factory()->patient()->create();
    $conversation = Conversation::factory()->create([
        'account_user_id' => $user->id,
    ]);

    expect($conversation->account)->not->toBeNull()
        ->and($conversation->account->id)->toBe($user->id);
});

test('conversation exposes patient relationship', function () {
    $conversation = Conversation::factory()->create();

    expect($conversation->patient)->not->toBeNull();
});

test('user exposes current conversation', function () {
    $user = User::factory()->patient()->create();
    $conversation = Conversation::factory()->create([
        'account_user_id' => $user->id,
    ]);

    expect($user->conversation)->not->toBeNull()
        ->and($user->conversation->id)->toBe($conversation->id);
});

test('patient exposes many historical conversations', function () {
    $user = User::factory()->patient()->create();
    $patient = $user->patient;

    Conversation::factory()->create([
        'account_user_id' => $user->id,
        'patient_id' => $patient->id,
    ]);

    Conversation::factory()->create([
        'account_user_id' => null,
        'patient_id' => $patient->id,
    ]);

    expect($patient->conversations)->toHaveCount(2);
});

test('unlinked factory state creates valid conversation', function () {
    $conversation = Conversation::factory()->unlinked()->create();

    expect($conversation->account_user_id)->not->toBeNull()
        ->and($conversation->patient_id)->toBeNull()
        ->and($conversation->isUnlinked())->toBeTrue();
});

test('linked factory state creates valid conversation', function () {
    $conversation = Conversation::factory()->linked()->create();

    expect($conversation->account_user_id)->not->toBeNull()
        ->and($conversation->patient_id)->not->toBeNull()
        ->and($conversation->isLinked())->toBeTrue();
});

test('historical factory state creates valid conversation', function () {
    $conversation = Conversation::factory()->historical()->create();

    expect($conversation->account_user_id)->toBeNull()
        ->and($conversation->patient_id)->not->toBeNull()
        ->and($conversation->isHistorical())->toBeTrue();
});

test('one account cannot own two current conversations', function () {
    $user = User::factory()->patient()->create();

    Conversation::factory()->create([
        'account_user_id' => $user->id,
    ]);

    Conversation::factory()->create([
        'account_user_id' => $user->id,
    ]);
})->throws(QueryException::class);

test('one patient may retain multiple historical conversations', function () {
    $user = User::factory()->patient()->create();
    $patient = $user->patient;

    Conversation::factory()->create([
        'account_user_id' => null,
        'patient_id' => $patient->id,
    ]);

    Conversation::factory()->create([
        'account_user_id' => null,
        'patient_id' => $patient->id,
    ]);

    expect($patient->conversations)->toHaveCount(2);
});
