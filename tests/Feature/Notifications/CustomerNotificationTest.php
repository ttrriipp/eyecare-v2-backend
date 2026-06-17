<?php

use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Actions\Billing\GenerateBillingForOrder;
use App\Actions\Orders\UpdateOrderStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Notifications\AppointmentStatusChanged;
use App\Notifications\BillingIssued;
use App\Notifications\OrderStatusChanged;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake();
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $this->seed(OrderStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);
    Notification::fake();
});

// ─── Appointment notifications ────────────────────────────────────────────────

test('customer is notified when appointment is confirmed', function () {
    $appointment = Appointment::factory()->create();

    app(UpdateAppointmentStatus::class)->handle($appointment, 'confirmed');

    Notification::assertSentTo($appointment->customer, AppointmentStatusChanged::class);
});

test('customer is notified when appointment is rescheduled', function () {
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => AppointmentStatus::query()->where('name', 'confirmed')->value('id'),
    ]);

    app(UpdateAppointmentStatus::class)->handle(
        appointment: $appointment,
        statusName: 'rescheduled',
        scheduledAt: now()->addDays(3),
    );

    Notification::assertSentTo($appointment->customer, AppointmentStatusChanged::class);
});

test('customer is notified when appointment is cancelled', function () {
    $appointment = Appointment::factory()->create();

    app(UpdateAppointmentStatus::class)->handle($appointment, 'cancelled');

    Notification::assertSentTo($appointment->customer, AppointmentStatusChanged::class);
});

test('customer is NOT notified when appointment is completed', function () {
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => AppointmentStatus::query()->where('name', 'confirmed')->value('id'),
    ]);

    app(UpdateAppointmentStatus::class)->handle($appointment, 'completed');

    Notification::assertNotSentTo($appointment->customer, AppointmentStatusChanged::class);
});

// ─── Order notifications ──────────────────────────────────────────────────────

test('customer is notified when order status changes', function () {
    $order = Order::factory()->create(['is_non_prescription' => true]);

    app(UpdateOrderStatus::class)->handle($order, 'under_review');

    Notification::assertSentTo($order->customer, OrderStatusChanged::class);
});

test('order notification payload contains correct data', function () {
    $order = Order::factory()->create(['is_non_prescription' => true]);

    app(UpdateOrderStatus::class)->handle($order, 'under_review');

    Notification::assertSentTo(
        $order->customer,
        OrderStatusChanged::class,
        function (OrderStatusChanged $notification) use ($order): bool {
            $data = $notification->toDatabase(new User);

            return $data->data['type'] === 'order_status_changed'
                && $data->data['related_id'] === $order->id
                && $data->data['related_type'] === 'order';
        },
    );
});

// ─── Billing notifications ────────────────────────────────────────────────────

test('customer is notified when billing is issued', function () {
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
        'order_status_id' => $confirmedStatus->id,
        'confirmed_at' => now(),
    ]);

    app(GenerateBillingForOrder::class)->handle($order);

    Notification::assertSentTo($order->customer, BillingIssued::class);
});

test('billing notification payload contains correct data', function () {
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
        'order_status_id' => $confirmedStatus->id,
        'total_amount' => '150.00',
        'confirmed_at' => now(),
    ]);

    app(GenerateBillingForOrder::class)->handle($order);

    Notification::assertSentTo(
        $order->customer,
        BillingIssued::class,
        function (BillingIssued $notification): bool {
            $data = $notification->toDatabase(new User);

            return $data->data['type'] === 'billing_issued'
                && $data->data['related_type'] === 'billing';
        },
    );
});
