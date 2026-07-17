<?php

use App\Filament\Resources\Conversations\Pages\ConversationChatPage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff and admin can load the chat page', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $this->actingAs($user);

    Livewire::test(ConversationChatPage::class)
        ->assertSuccessful();
})->with(['staff', 'admin']);

test('chat page lists all conversations', function () {
    $staff = User::factory()->staff()->create();
    Conversation::factory()->count(3)->create();

    $this->actingAs($staff);

    Livewire::test(ConversationChatPage::class)
        ->assertSee(Conversation::first()->customer->name);
});

test('chat page confines scrolling to the conversation and message sections', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();

    $this->actingAs($staff);

    $html = Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id)
        ->html();

    expect($html)
        ->toContain('fi-height-full')
        ->toContain('data-chat-layout')
        ->toContain('data-chat-scroll-region="conversations"')
        ->toContain('data-chat-scroll-region="messages"')
        ->not->toContain('h-[calc(100vh-12rem)]');
});

test('selecting a conversation loads its messages', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $conversation->customer_id,
        'body' => 'Hello from customer.',
    ]);

    $this->actingAs($staff);

    Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id)
        ->assertSet('selectedConversationId', $conversation->id)
        ->assertSee('Hello from customer.');
});

test('image attachments are displayed in the chat', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $conversation->customer_id,
    ]);
    $attachment = MessageAttachment::factory()->create([
        'message_id' => $message->id,
        'original_name' => 'customer-screenshot.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $this->actingAs($staff);

    $html = Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id)
        ->html();

    expect($html)
        ->toContain('data-message-image-attachment')
        ->toContain("/attachments/{$attachment->id}/preview")
        ->toContain('alt="customer-screenshot.jpg"');
});

test('staff can preview a private image attachment inline', function () {
    Storage::fake('local');

    $staff = User::factory()->staff()->create();
    $message = Message::factory()->create();
    Storage::disk('local')->put('attachments/customer-screenshot.jpg', 'image-content');
    $attachment = MessageAttachment::factory()->create([
        'message_id' => $message->id,
        'file_path' => 'attachments/customer-screenshot.jpg',
        'original_name' => 'customer-screenshot.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $this->actingAs($staff)
        ->get("/attachments/{$attachment->id}/preview")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Content-Disposition', 'inline; filename=customer-screenshot.jpg');
});

test('customers cannot use the panel attachment preview', function () {
    Storage::fake('local');

    $customer = User::factory()->customer()->create();
    $attachment = MessageAttachment::factory()->create([
        'mime_type' => 'image/jpeg',
    ]);

    $this->actingAs($customer)
        ->get("/attachments/{$attachment->id}/preview")
        ->assertForbidden();
});

test('staff can send a reply', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id)
        ->set('replyBody', 'Hello from staff.')
        ->call('sendReply')
        ->assertNotified();

    $this->assertDatabaseHas(Message::class, [
        'conversation_id' => $conversation->id,
        'sender_id' => $staff->id,
        'body' => 'Hello from staff.',
    ]);
});

test('reply body is required', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id)
        ->set('replyBody', '')
        ->call('sendReply')
        ->assertHasErrors(['replyBody' => 'required']);
});

test('reply clears body after sending', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id)
        ->set('replyBody', 'Hello.')
        ->call('sendReply')
        ->assertSet('replyBody', '');
});
