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
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\BillingStatusSeeder;
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

    $log = AuditLog::query()->where('action', 'appointment.status_changed')->first();
    expect($log->metadata)->toMatchArray(['from' => 'pending', 'to' => 'confirmed']);
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

    app(UpdateOrderStatus::class)->handle($order, 'confirmed');

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

    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
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

    $billing = Billing::factory()->issued()->create();
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

test('product creation creates an audit log', function () {
    $staff = User::factory()->staff()->create();
    Auth::login($staff);

    $product = Product::factory()->create();

    $this->assertDatabaseHas(AuditLog::class, [
        'actor_id' => $staff->id,
        'subject_type' => $product->getMorphClass(),
        'subject_id' => $product->id,
        'action' => 'product.created',
    ]);
});

test('product update creates an audit log', function () {
    $staff = User::factory()->staff()->create();
    Auth::login($staff);

    $product = Product::factory()->create();
    $product->update(['name' => 'Updated Name']);

    $this->assertDatabaseHas(AuditLog::class, [
        'subject_id' => $product->id,
        'action' => 'product.updated',
    ]);
});

test('product soft delete creates an audit log', function () {
    $staff = User::factory()->staff()->create();
    Auth::login($staff);

    $product = Product::factory()->create();
    $product->delete();

    $this->assertDatabaseHas(AuditLog::class, [
        'subject_id' => $product->id,
        'action' => 'product.deleted',
    ]);
});

test('user creation creates an audit log', function () {
    $admin = User::factory()->admin()->create();
    Auth::login($admin);

    $newUser = User::factory()->staff()->create();

    $this->assertDatabaseHas(AuditLog::class, [
        'subject_type' => $newUser->getMorphClass(),
        'subject_id' => $newUser->id,
        'action' => 'user.created',
    ]);
});

test('user role change creates an audit log', function () {
    $admin = User::factory()->admin()->create();
    Auth::login($admin);

    $user = User::factory()->staff()->create();
    $customerRole = Role::query()->where('name', 'customer')->firstOrFail();
    $user->update(['role_id' => $customerRole->id]);

    $this->assertDatabaseHas(AuditLog::class, [
        'subject_id' => $user->id,
        'action' => 'user.role_changed',
    ]);
});

test('every appointment status transition creates an audit log entry', function () {
    $staff = User::factory()->staff()->create();
    Auth::login($staff);

    // pending → confirmed → rescheduled → cancelled (covers all non-completed transitions)
    $appointment = Appointment::factory()->create();

    app(UpdateAppointmentStatus::class)->handle($appointment, 'confirmed');
    app(UpdateAppointmentStatus::class)->handle($appointment->fresh(), 'rescheduled', now()->addDay());
    app(UpdateAppointmentStatus::class)->handle($appointment->fresh(), 'cancelled');

    $logs = AuditLog::query()
        ->where('subject_type', $appointment->getMorphClass())
        ->where('subject_id', $appointment->id)
        ->where('action', 'appointment.status_changed')
        ->get();

    expect($logs)->toHaveCount(3)
        ->and($logs->pluck('metadata')->map(fn ($m) => $m['to']))->toContain('confirmed', 'rescheduled', 'cancelled');
});

test('every order status transition creates an audit log entry', function () {
    $staff = User::factory()->staff()->create();
    Auth::login($staff);

    $order = Order::factory()->create([
        'order_status_id' => OrderStatus::query()->where('name', 'requested')->value('id'),
        'is_non_prescription' => true,
    ]);

    // requested → confirmed → preparing → ready_for_pickup → completed
    foreach (['confirmed', 'preparing', 'ready_for_pickup', 'completed'] as $status) {
        app(UpdateOrderStatus::class)->handle($order->fresh(), $status);
    }

    $logs = AuditLog::query()
        ->where('subject_type', $order->getMorphClass())
        ->where('subject_id', $order->id)
        ->where('action', 'order.status_changed')
        ->get();

    expect($logs)->toHaveCount(4)
        ->and($logs->pluck('metadata')->map(fn ($m) => $m['to']))
        ->toContain('confirmed', 'preparing', 'ready_for_pickup', 'completed');
});
