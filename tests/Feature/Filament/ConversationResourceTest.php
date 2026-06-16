<?php

use App\Filament\Resources\Conversations\Pages\ConversationChatPage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
