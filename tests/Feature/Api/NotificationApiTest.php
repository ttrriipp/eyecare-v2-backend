<?php

use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderStatusChanged;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->customer = User::factory()->customer()->create();
});

// ─── GET /notifications ───────────────────────────────────────────────────────

test('customer can list their notifications newest first', function () {
    $order = Order::factory()->create(['customer_id' => $this->customer->id, 'is_non_prescription' => true]);

    $this->customer->notify(new OrderStatusChanged($order->load('status')));
    $this->customer->notify(new OrderStatusChanged($order->load('status')));

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'type', 'title', 'body', 'action_url', 'related_type', 'related_id', 'read_at', 'created_at']],
        ]);
});

test('customer only sees their own notifications', function () {
    $other = User::factory()->customer()->create();
    $order = Order::factory()->create(['customer_id' => $other->id, 'is_non_prescription' => true]);

    $other->notify(new OrderStatusChanged($order->load('status')));

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('unauthenticated user cannot access notifications', function () {
    $this->getJson('/api/notifications')->assertUnauthorized();
});

// ─── GET /notifications/unread-count ─────────────────────────────────────────

test('unread count returns correct number', function () {
    $order = Order::factory()->create(['customer_id' => $this->customer->id, 'is_non_prescription' => true]);

    $this->customer->notify(new OrderStatusChanged($order->load('status')));
    $this->customer->notify(new OrderStatusChanged($order->load('status')));

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('unread_count', 2);
});

// ─── POST /notifications/{id}/mark-read ──────────────────────────────────────

test('customer can mark a single notification as read', function () {
    $order = Order::factory()->create(['customer_id' => $this->customer->id, 'is_non_prescription' => true]);
    $this->customer->notify(new OrderStatusChanged($order->load('status')));

    $notification = $this->customer->notifications()->first();

    $this->actingAs($this->customer, 'sanctum')
        ->postJson("/api/notifications/{$notification->id}/mark-read")
        ->assertOk();

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('customer cannot mark another customer notification as read', function () {
    $other = User::factory()->customer()->create();
    $order = Order::factory()->create(['customer_id' => $other->id, 'is_non_prescription' => true]);
    $other->notify(new OrderStatusChanged($order->load('status')));

    $notification = $other->notifications()->first();

    $this->actingAs($this->customer, 'sanctum')
        ->postJson("/api/notifications/{$notification->id}/mark-read")
        ->assertForbidden();
});

// ─── POST /notifications/mark-all-read ───────────────────────────────────────

test('customer can mark all notifications as read', function () {
    $order = Order::factory()->create(['customer_id' => $this->customer->id, 'is_non_prescription' => true]);

    $this->customer->notify(new OrderStatusChanged($order->load('status')));
    $this->customer->notify(new OrderStatusChanged($order->load('status')));

    $this->actingAs($this->customer, 'sanctum')
        ->postJson('/api/notifications/mark-all-read')
        ->assertOk();

    expect($this->customer->unreadNotifications()->count())->toBe(0);
});

test('mark-all-read only affects the authenticated user', function () {
    $other = User::factory()->customer()->create();
    $order = Order::factory()->create(['customer_id' => $other->id, 'is_non_prescription' => true]);
    $other->notify(new OrderStatusChanged($order->load('status')));

    $this->actingAs($this->customer, 'sanctum')
        ->postJson('/api/notifications/mark-all-read')
        ->assertOk();

    expect($other->unreadNotifications()->count())->toBe(1);
});
