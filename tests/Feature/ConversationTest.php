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
    $conversation = Conversation::query()->create(['patient_id' => $patient->patient->id]);

    $this->actingAs($patient)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful()
        ->assertJsonPath('data.patient_id', $patient->patient->id)
        ->assertJsonMissing(['customer_id']);
});

test('patient can access own conversation', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create(['patient_id' => $patient->patient->id]);

    $this->actingAs($patient)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful();
});

test('patient conversation messages do not include another patients conversation messages', function () {
    $patient1 = User::factory()->patient()->create();
    $patient2 = User::factory()->patient()->create();
    $conversation = Conversation::query()->create(['patient_id' => $patient2->patient->id]);
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
    $conversation = Conversation::query()->create(['patient_id' => $patient->patient->id]);
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
    $conversation = Conversation::query()->create(['patient_id' => $patient2->patient->id]);
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
