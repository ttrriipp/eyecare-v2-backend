<?php

use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Actions\Audit\CreateAuditLog;
use App\Actions\Billing\GenerateBillingForOrder;
use App\Actions\Billing\RecalculateBillingBalance;
use App\Actions\Inventory\RecordInventoryMovement;
use App\Actions\Orders\UpdateOrderStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Billing;
use App\Models\Feedback;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\InventoryMovementStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Database\Seeders\PaymentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $this->seed(OrderStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);
    $this->seed(PaymentStatusSeeder::class);
    $this->seed(InventoryMovementStatusSeeder::class);
});

test('CreateAuditLog records actor subject action and metadata', function () {
    $staff = User::factory()->staff()->create();
    Auth::login($staff);

    $appointment = Appointment::factory()->create();

    app(CreateAuditLog::class)->handle(
        subject: $appointment,
        action: 'appointment.status_changed',
        metadata: ['from' => 'pending', 'to' => 'confirmed'],
    );

    $this->assertDatabaseHas(AuditLog::class, [
        'actor_id' => $staff->id,
        'subject_type' => $appointment->getMorphClass(),
        'subject_id' => $appointment->id,
        'action' => 'appointment.status_changed',
    ]);

    $log = AuditLog::query()->first();
    expect($log->metadata)->toBe(['from' => 'pending', 'to' => 'confirmed']);
});

test('CreateAuditLog accepts explicit actor id and null metadata', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    app(CreateAuditLog::class)->handle(
        subject: $appointment,
        action: 'appointment.status_changed',
        actorId: $staff->id,
    );

    $this->assertDatabaseHas(AuditLog::class, [
        'actor_id' => $staff->id,
        'subject_id' => $appointment->id,
        'metadata' => null,
    ]);
});

test('appointment status change creates an audit log', function () {
    $staff = User::factory()->staff()->create();
    Auth::login($staff);

    $appointment = Appointment::factory()->create();

    app(UpdateAppointmentStatus::class)->handle($appointment, 'confirmed');

    $this->assertDatabaseHas(AuditLog::class, [
        'actor_id' => $staff->id,
        'subject_type' => $appointment->getMorphClass(),
        'subject_id' => $appointment->id,
        'action' => 'appointment.status_changed',
    ]);
});

test('order status change creates an audit log', function () {
    $staff = User::factory()->staff()->create();
    Auth::login($staff);

    $order = Order::factory()->create();

    app(UpdateOrderStatus::class)->handle($order, 'under_review');

    $this->assertDatabaseHas(AuditLog::class, [
        'subject_type' => $order->getMorphClass(),
        'subject_id' => $order->id,
        'action' => 'order.status_changed',
    ]);
});

test('inventory movement creates an audit log', function () {
    $staff = User::factory()->staff()->create();
    Auth::login($staff);

    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    app(RecordInventoryMovement::class)->handle(
        variant: $variant,
        quantityChange: -2,
        type: 'manual_adjustment',
    );

    $this->assertDatabaseHas(AuditLog::class, [
        'action' => 'inventory.movement_recorded',
    ]);
});

test('billing generation creates an audit log', function () {
    $staff = User::factory()->staff()->create();
    Auth::login($staff);

    $confirmedStatus = \App\Models\OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
        'confirmed_at' => now(),
    ]);

    app(GenerateBillingForOrder::class)->handle($order);

    $this->assertDatabaseHas(AuditLog::class, [
        'action' => 'billing.generated',
    ]);
});

test('payment record triggers billing balance recalculation audit log', function () {
    $staff = User::factory()->staff()->create();
    Auth::login($staff);

    $billing = Billing::factory()->draft()->create();
    $payment = Payment::factory()->posted()->create(['billing_id' => $billing->id]);

    app(RecalculateBillingBalance::class)->handle($billing);

    $this->assertDatabaseHas(AuditLog::class, [
        'subject_type' => $billing->getMorphClass(),
        'subject_id' => $billing->id,
        'action' => 'billing.balance_recalculated',
    ]);
});

test('feedback submission creates an audit log', function () {
    $customer = User::factory()->customer()->create();

    $feedback = Feedback::factory()->create(['customer_id' => $customer->id]);

    app(CreateAuditLog::class)->handle(
        subject: $feedback,
        action: 'feedback.submitted',
        actorId: $customer->id,
    );

    $this->assertDatabaseHas(AuditLog::class, [
        'actor_id' => $customer->id,
        'subject_type' => $feedback->getMorphClass(),
        'action' => 'feedback.submitted',
    ]);
});
