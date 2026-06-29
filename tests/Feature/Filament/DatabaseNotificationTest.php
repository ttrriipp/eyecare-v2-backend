<?php

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VisitReason;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(OrderStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $this->staff = User::factory()->staff()->create();
});

test('booking an appointment creates a notification for staff', function () {
    Http::fake();
    $customer = User::factory()->customer()->create();
    VisitReason::factory()->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => VisitReason::query()->first()->id,
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
        ])
        ->assertCreated();

    expect(DatabaseNotification::query()->where('notifiable_id', $this->staff->id)->count())->toBeGreaterThan(0);

    $notification = DatabaseNotification::query()->where('notifiable_id', $this->staff->id)->first();
    expect($notification->data['title'])->toBe('New Appointment Booked');
});

test('placing an order creates a notification for staff', function () {
    $customer = User::factory()->customer()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])
        ->assertCreated();

    $notification = DatabaseNotification::query()->where('notifiable_id', $this->staff->id)->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['title'])->toBe('New Order Request');
});

test('customer cancelling an appointment creates a warning notification', function () {
    Http::fake();
    $customer = User::factory()->customer()->create();
    $pendingId = AppointmentStatus::query()->where('name', 'pending')->value('id');

    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $pendingId,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/appointments/{$appointment->id}/cancel")
        ->assertOk();

    $notification = DatabaseNotification::query()->where('notifiable_id', $this->staff->id)->latest()->first();
    expect($notification->data['title'])->toBe('Appointment Cancelled by Customer');
});

test('customer cancelling an order creates a warning notification', function () {
    $customer = User::factory()->customer()->create();
    $requestedId = OrderStatus::query()->where('name', 'requested')->value('id');

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'order_status_id' => $requestedId,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/orders/{$order->id}/cancel")
        ->assertOk();

    $notification = DatabaseNotification::query()->where('notifiable_id', $this->staff->id)->latest()->first();
    expect($notification->data['title'])->toBe('Order Cancelled by Customer');
});
