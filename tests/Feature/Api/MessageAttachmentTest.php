<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

test('customers can send a message with an attachment', function () {
    $customer = User::factory()->customer()->create();
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $file = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'See attached.',
            'attachment' => $file,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.body', 'See attached.')
        ->assertJsonCount(1, 'data.attachments')
        ->assertJsonPath('data.attachments.0.original_name', 'document.pdf')
        ->assertJsonPath('data.attachments.0.mime_type', 'application/pdf');

    $this->assertDatabaseHas(MessageAttachment::class, [
        'original_name' => 'document.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $attachment = MessageAttachment::query()->first();
    Storage::disk('local')->assertExists($attachment->file_path);
});

test('attachment is stored privately', function () {
    $customer = User::factory()->customer()->create();
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Private file.',
            'attachment' => $file,
        ])
        ->assertCreated();

    $attachment = MessageAttachment::query()->first();
    Storage::disk('public')->assertMissing($attachment->file_path);
});

test('attachment is optional when sending a message', function () {
    $customer = User::factory()->customer()->create();
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'No file here.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.attachments', []);
});

test('attachment is rejected for disallowed mime types', function () {
    $customer = User::factory()->customer()->create();
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $file = UploadedFile::fake()->create('script.exe', 100, 'application/x-msdownload');

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Malicious.',
            'attachment' => $file,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attachment']);
});

test('attachment is rejected when file exceeds 10mb', function () {
    $customer = User::factory()->customer()->create();
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $file = UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf');

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Too big.',
            'attachment' => $file,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attachment']);
});

test('only authorized participants can access attachment metadata', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);
    $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_id' => $customer->id]);
    MessageAttachment::factory()->create(['message_id' => $message->id]);

    $this->actingAs($otherCustomer, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/messages")
        ->assertNotFound();
});
