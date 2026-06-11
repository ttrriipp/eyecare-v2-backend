<?php

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('customers can create a conversation without context', function () {
    $customer = User::factory()->customer()->create();

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson('/api/conversations', [
            'subject' => 'General inquiry',
            'body' => 'I have a question about my eyes.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.subject', 'General inquiry')
        ->assertJsonPath('data.messages.0.body', 'I have a question about my eyes.');

    $this->assertDatabaseHas(Conversation::class, [
        'customer_id' => $customer->id,
        'subject' => 'General inquiry',
    ]);
});

test('customers can create a conversation with appointment context', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create(['customer_id' => $customer->id]);

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson('/api/conversations', [
            'appointment_id' => $appointment->id,
            'body' => 'About my upcoming appointment.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.appointment_id', $appointment->id);
});

test('customers can create a conversation with order context', function () {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->create(['customer_id' => $customer->id]);

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson('/api/conversations', [
            'order_id' => $order->id,
            'body' => 'About my order.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.order_id', $order->id);
});

test('customers cannot link a conversation to another customers appointment', function () {
    $customer = User::factory()->customer()->create();
    $otherAppointment = Appointment::factory()->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/conversations', [
            'appointment_id' => $otherAppointment->id,
            'body' => 'Hello.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment_id']);
});

test('customers cannot link a conversation to another customers order', function () {
    $customer = User::factory()->customer()->create();
    $otherOrder = Order::factory()->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/conversations', [
            'order_id' => $otherOrder->id,
            'body' => 'Hello.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['order_id']);
});

test('customers can list only their own conversations', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();

    $ownConversations = Conversation::factory()->count(2)->create(['customer_id' => $customer->id]);
    Conversation::factory()->create(['customer_id' => $otherCustomer->id]);

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson('/api/conversations');

    $response->assertSuccessful();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toEqualCanonicalizing($ownConversations->pluck('id')->all());
});

test('customers can send a message to their own conversation', function () {
    $customer = User::factory()->customer()->create();
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Following up.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Following up.');

    $this->assertDatabaseHas(Message::class, [
        'conversation_id' => $conversation->id,
        'sender_id' => $customer->id,
        'body' => 'Following up.',
    ]);
});

test('customers cannot send a message to another customers conversation', function () {
    $customer = User::factory()->customer()->create();
    $otherConversation = Conversation::factory()->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/conversations/{$otherConversation->id}/messages", [
            'body' => 'Hello.',
        ])
        ->assertNotFound();
});

test('customers can view messages in their own conversation', function () {
    $customer = User::factory()->customer()->create();
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);
    Message::factory()->count(3)->create(['conversation_id' => $conversation->id, 'sender_id' => $customer->id]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/messages")
        ->assertSuccessful()
        ->assertJsonCount(3, 'data');
});

test('staff can view and reply to any conversation', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Staff reply.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Staff reply.');

    $this->assertDatabaseHas(Message::class, [
        'conversation_id' => $conversation->id,
        'sender_id' => $staff->id,
    ]);
});

test('unauthenticated users cannot access conversation endpoints', function () {
    $this->postJson('/api/conversations', [])->assertUnauthorized();
    $this->getJson('/api/conversations')->assertUnauthorized();
});

test('staff cannot create or list conversations via the customer api', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff, 'sanctum')
        ->postJson('/api/conversations', ['body' => 'Hello.'])
        ->assertForbidden();

    $this->actingAs($staff, 'sanctum')
        ->getJson('/api/conversations')
        ->assertForbidden();
});

test('conversation body is required when creating', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/conversations', ['subject' => 'Test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body']);
});
