<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('conversation api response uses patient_id not customer_id', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($patient)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful()
        ->assertJsonPath('data.patient_id', $patient->patient->id)
        ->assertJsonMissing(['customer_id']);
});

test('patient can access own conversation', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($patient)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful();
});

test('patient conversation messages do not include another patients conversation messages', function () {
    $patient1 = User::factory()->patient()->create();
    $patient2 = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient2->id,
        'patient_id' => $patient2->patient->id,
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient2->id,
        'body' => 'Other patient protected message',
    ]);

    $this->actingAs($patient1)
        ->getJson('/api/v1/conversation/messages')
        ->assertSuccessful()
        ->assertJsonCount(0, 'data')
        ->assertJsonMissing(['Other patient protected message']);
});

test('patient can download own conversation attachment through singular attachment route', function () {
    Storage::fake('local');

    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);
    $attachment = MessageAttachment::factory()->create([
        'message_id' => $message->id,
        'file_path' => 'attachments/own-prescription.pdf',
        'original_name' => 'own-prescription.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put($attachment->file_path, 'private-file');

    $this->actingAs($patient)
        ->get("/api/v1/conversation/attachments/{$attachment->id}")
        ->assertDownload('own-prescription.pdf');
});

test('patient cannot download another patients conversation attachment', function () {
    Storage::fake('local');

    $patient1 = User::factory()->patient()->create();
    $patient2 = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient2->id,
        'patient_id' => $patient2->patient->id,
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient2->id,
    ]);
    $attachment = MessageAttachment::factory()->create([
        'message_id' => $message->id,
        'file_path' => 'attachments/other-prescription.pdf',
        'original_name' => 'other-prescription.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put($attachment->file_path, 'private-file');

    $this->actingAs($patient1)
        ->get("/api/v1/conversation/attachments/{$attachment->id}")
        ->assertNotFound();
});

test('linked patient resolves exactly one conversation', function () {
    $patient = User::factory()->patient()->create();

    $this->actingAs($patient)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful()
        ->assertJsonPath('data.patient_id', $patient->patient->id);

    // Second call returns same conversation
    $this->actingAs($patient)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful()
        ->assertJsonPath('data.patient_id', $patient->patient->id);

    expect(Conversation::where('account_user_id', $patient->id)->count())->toBe(1);
});

test('linked conversation messages are ordered oldest first', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'body' => 'First message',
    ]);

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'body' => 'Second message',
    ]);

    $this->actingAs($patient)
        ->getJson('/api/v1/conversation/messages')
        ->assertSuccessful()
        ->assertJsonPath('data.0.body', 'First message')
        ->assertJsonPath('data.1.body', 'Second message');
});

test('cross-account attachment access returns not found', function () {
    Storage::fake('local');

    $patient1 = User::factory()->patient()->create();
    $patient2 = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient2->id,
        'patient_id' => $patient2->patient->id,
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient2->id,
    ]);
    $attachment = MessageAttachment::factory()->create([
        'message_id' => $message->id,
        'file_path' => 'attachments/secret.pdf',
        'original_name' => 'secret.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put($attachment->file_path, 'private-file');

    // Patient1 cannot access Patient2's attachment
    $this->actingAs($patient1)
        ->get("/api/v1/conversation/attachments/{$attachment->id}")
        ->assertNotFound();

    // Non-existent ID also returns 404
    $this->actingAs($patient1)
        ->get('/api/v1/conversation/attachments/99999')
        ->assertNotFound();
});
