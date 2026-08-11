<?php

/**
 * Tests for unlinked account conversation API.
 *
 * @see tasks/todo.md Task 4
 */

use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->patientRole = Role::where('name', 'patient')->first();
});

test('unlinked account can open conversation', function () {
    $user = User::factory()->create();
    $user->roles()->attach($this->patientRole);

    $this->actingAs($user)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful()
        ->assertJsonPath('data.access_level', 'general_inquiry')
        ->assertJsonPath('data.capabilities.can_upload_attachments', false)
        ->assertJsonPath('data.capabilities.can_create_context_links', false);
});

test('linked account sees linked_patient access level', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful()
        ->assertJsonPath('data.access_level', 'linked_patient')
        ->assertJsonPath('data.capabilities.can_upload_attachments', true);
});

test('unlinked conversation response contains no patient data', function () {
    $user = User::factory()->create();
    $user->roles()->attach($this->patientRole);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful();

    $response->assertJsonMissing(['patient_number', 'full_name', 'date_of_birth']);
});

test('unlinked account can send text message', function () {
    $user = User::factory()->create();
    $user->roles()->attach($this->patientRole);

    $this->actingAs($user)
        ->postJson('/api/v1/conversation/messages', [
            'body' => 'Do you have the Vista Classic frame available?',
        ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Do you have the Vista Classic frame available?');
});

test('unlinked account cannot upload attachment', function () {
    $user = User::factory()->create();
    $user->roles()->attach($this->patientRole);

    $this->actingAs($user)
        ->postJson('/api/v1/conversation/messages', [
            'body' => 'Here is my prescription',
            'attachment' => UploadedFile::fake()->create('prescription.pdf'),
        ])
        ->assertStatus(422);
});

test('structured context input is rejected', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/conversation/messages', [
            'body' => 'Check this frame',
            'contexts' => [['type' => 'product', 'id' => 1]],
        ])
        ->assertStatus(422);
});

test('conversation is lazily created for unlinked account', function () {
    $user = User::factory()->create();
    $user->roles()->attach($this->patientRole);

    expect(Conversation::where('account_user_id', $user->id)->count())->toBe(0);

    $this->actingAs($user)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful();

    expect(Conversation::where('account_user_id', $user->id)->count())->toBe(1);
});

test('second call returns same conversation', function () {
    $user = User::factory()->create();
    $user->roles()->attach($this->patientRole);

    $first = $this->actingAs($user)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful();

    $second = $this->actingAs($user)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful();

    expect($first->json('data.id'))->toBe($second->json('data.id'));
});
