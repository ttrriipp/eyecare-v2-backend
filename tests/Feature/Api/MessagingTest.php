<?php

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('GET /conversations creates and returns single conversation for customer', function () {
    $customer = User::factory()->customer()->create();

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson('/api/conversations');

    $response->assertSuccessful()
        ->assertJsonPath('data.customer_id', $customer->id);

    $this->assertDatabaseHas(Conversation::class, ['customer_id' => $customer->id]);
    expect(Conversation::where('customer_id', $customer->id)->count())->toBe(1);
});

test('GET /conversations returns existing conversation without creating a new one', function () {
    $customer = User::factory()->customer()->create();
    $existing = Conversation::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($customer, 'sanctum')
        ->getJson('/api/conversations')
        ->assertSuccessful()
        ->assertJsonPath('data.id', $existing->id);

    expect(Conversation::where('customer_id', $customer->id)->count())->toBe(1);
});

test('staff cannot access GET /conversations customer endpoint', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff, 'sanctum')
        ->getJson('/api/conversations')
        ->assertForbidden();
});

test('unauthenticated users cannot access conversation endpoints', function () {
    $this->getJson('/api/conversations')->assertUnauthorized();
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

test('message body is required when sending a message', function () {
    $customer = User::factory()->customer()->create();
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body']);
});

test('customer can send a message with appointment context link', function () {
    $customer = User::factory()->customer()->create();
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);
    $appointment = Appointment::factory()->create(['customer_id' => $customer->id]);

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'About my appointment.',
            'contexts' => [
                ['type' => 'appointment', 'id' => $appointment->id],
            ],
        ])
        ->assertCreated();

    $messageId = $response->json('data.id');
    $this->assertDatabaseHas('message_context_links', [
        'message_id' => $messageId,
        'contextable_type' => Appointment::class,
        'contextable_id' => $appointment->id,
    ]);
});

test('customer can send a message with order context link', function () {
    $customer = User::factory()->customer()->create();
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);
    $order = Order::factory()->create(['customer_id' => $customer->id]);

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'About my order.',
            'contexts' => [
                ['type' => 'order', 'id' => $order->id],
            ],
        ])
        ->assertCreated();

    $messageId = $response->json('data.id');
    $this->assertDatabaseHas('message_context_links', [
        'message_id' => $messageId,
        'contextable_type' => Order::class,
        'contextable_id' => $order->id,
    ]);
});

test('GET messages returns context links on each message', function () {
    $customer = User::factory()->customer()->create();
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);
    $appointment = Appointment::factory()->create(['customer_id' => $customer->id]);

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $customer->id,
    ]);
    $message->contextLinks()->create([
        'contextable_type' => Appointment::class,
        'contextable_id' => $appointment->id,
    ]);

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/messages")
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(1, 'data.0.contexts');

    expect($response->json('data.0.contexts.0.id'))->toBe($appointment->id);
});

test('contexts type must be valid', function () {
    $customer = User::factory()->customer()->create();
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Hello.',
            'contexts' => [
                ['type' => 'invalid_type', 'id' => 1],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contexts.0.type']);
});
