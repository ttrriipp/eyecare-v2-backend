<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('message attachments use a private logical disk by default', function (): void {
    $diskName = config('filesystems.message_attachments_disk');

    expect($diskName)->toBe('message_attachments')
        ->and(config("filesystems.disks.{$diskName}.visibility"))->toBe('private')
        ->and(config("filesystems.disks.{$diskName}.driver"))->toBe('local');
});

test('linked patient uploads an attachment to the configured disk', function (): void {
    Storage::fake('message_attachments');
    Storage::fake('local');

    $patient = User::factory()->patient()->create();

    $response = $this->actingAs($patient)->post('/api/v1/conversation/messages', [
        'body' => 'Please review this prescription.',
        'attachment' => UploadedFile::fake()->create('prescription.pdf', 10, 'application/pdf'),
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.attachments.0.original_name', 'prescription.pdf');

    $attachment = MessageAttachment::query()->sole();

    Storage::disk('message_attachments')->assertExists($attachment->file_path);
    expect(Storage::disk('message_attachments')->getVisibility($attachment->file_path))->toBe('private')
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('attachment download honors a configured non-default private disk', function (): void {
    config([
        'filesystems.message_attachments_disk' => 'message_attachments_test',
        'filesystems.disks.message_attachments_test' => [
            'driver' => 'local',
            'root' => storage_path('app/private/testing-message-attachments'),
            'visibility' => 'private',
            'throw' => true,
        ],
    ]);
    Storage::fake('message_attachments_test');

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
        'file_path' => 'attachments/configured-disk.pdf',
        'original_name' => 'configured-disk.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('message_attachments_test')->put($attachment->file_path, 'private-file');

    $this->actingAs($patient)
        ->get("/api/v1/conversation/attachments/{$attachment->id}")
        ->assertDownload('configured-disk.pdf');
});

test('missing attachment objects remain a safe not found response', function (): void {
    Storage::fake('message_attachments');

    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);
    $attachment = MessageAttachment::factory()->create(['message_id' => $message->id]);

    $this->actingAs($patient)
        ->get("/api/v1/conversation/attachments/{$attachment->id}")
        ->assertNotFound();
});
