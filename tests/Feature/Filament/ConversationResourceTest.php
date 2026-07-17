<?php

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Conversations\Pages\ConversationChatPage;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\User;
use App\Models\VisitReason;
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
        ->toContain('fi-conversation-chat-page')
        ->toContain('data-chat-layout')
        ->toContain('data-chat-scroll-region="conversations"')
        ->toContain('data-chat-scroll-region="messages"')
        ->not->toContain('h-[calc(100vh-12rem)]');

    expect(file_get_contents(resource_path('css/filament/admin/theme.css')))
        ->toContain('.fi-page.fi-conversation-chat-page')
        ->toContain('height: calc(100dvh - 4rem)')
        ->toContain('overflow: hidden')
        ->toContain('min-height: 0')
        ->toContain('flex: 1 1 0%')
        ->toContain('height: auto');
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

test('selected conversations initialize at the latest message', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();
    Message::factory()->count(5)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $conversation->customer_id,
    ]);

    $this->actingAs($staff);

    $html = Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id)
        ->html();

    expect($html)
        ->toContain("wire:key=\"conversation-messages-{$conversation->id}\"")
        ->toContain('data-scroll-to-latest-on-init')
        ->toContain('$el.scrollTop = $el.scrollHeight');
});

test('image attachments are displayed in the chat', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $conversation->customer_id,
        'body' => 'Attachment',
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
        ->toContain('alt="customer-screenshot.jpg"')
        ->toContain('class="inline-block max-w-full align-top"')
        ->not->toContain('data-message-body');
});

test('image attachments keep meaningful message captions', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $conversation->customer_id,
        'body' => 'Here is my payment screenshot.',
    ]);
    MessageAttachment::factory()->create([
        'message_id' => $message->id,
        'mime_type' => 'image/jpeg',
    ]);

    $this->actingAs($staff);

    Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id)
        ->assertSeeHtml('data-message-body')
        ->assertSee('Here is my payment screenshot.');
});

test('appointment context is shown as a clickable card without the generated summary body', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();
    $visitReason = VisitReason::factory()->create(['name' => 'Follow-up']);
    $appointment = Appointment::factory()->create([
        'customer_id' => $conversation->customer_id,
        'visit_reason_id' => $visitReason->id,
        'scheduled_at' => '2026-07-14 14:30:00',
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $conversation->customer_id,
        'body' => '📅 Appointment: Follow-up — 2026-07-14',
    ]);
    $message->contextLinks()->create([
        'contextable_type' => Appointment::class,
        'contextable_id' => $appointment->id,
    ]);

    $this->actingAs($staff);

    $html = Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id)
        ->html();

    expect($html)
        ->toContain('data-message-context-card="appointment"')
        ->toContain(AppointmentResource::getUrl('edit', ['record' => $appointment]))
        ->toContain($appointment->appointment_number)
        ->toContain('Follow-up')
        ->toContain('Jul 14, 2026')
        ->not->toContain('data-message-body');
});

test('order context is shown as a clickable card without the generated summary body', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();
    $order = Order::factory()->create([
        'customer_id' => $conversation->customer_id,
        'total_amount' => 2499.50,
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $conversation->customer_id,
        'body' => "📦 Order #{$order->order_number}",
    ]);
    $message->contextLinks()->create([
        'contextable_type' => Order::class,
        'contextable_id' => $order->id,
    ]);

    $this->actingAs($staff);

    $html = Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id)
        ->html();

    expect($html)
        ->toContain('data-message-context-card="order"')
        ->toContain(OrderResource::getUrl('edit', ['record' => $order]))
        ->toContain($order->order_number)
        ->toContain('₱2,499.50')
        ->not->toContain('data-message-body');
});

test('context cards keep meaningful message captions', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();
    $appointment = Appointment::factory()->create([
        'customer_id' => $conversation->customer_id,
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $conversation->customer_id,
        'body' => 'Can you check this appointment for me?',
    ]);
    $message->contextLinks()->create([
        'contextable_type' => Appointment::class,
        'contextable_id' => $appointment->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id)
        ->assertSeeHtml('data-message-context-card="appointment"')
        ->assertSeeHtml('data-message-body')
        ->assertSee('Can you check this appointment for me?');
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
