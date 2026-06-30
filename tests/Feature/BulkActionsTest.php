<?php

use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\SmsNotifications\Pages\ListSmsNotifications;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\NotificationStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\SmsNotification;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(OrderStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $this->actingAs(User::factory()->admin()->create());
});

// ─── Appointments: Bulk Confirm ───────────────────────────────────────────────

test('bulk confirm transitions all pending appointments to confirmed', function () {
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $appointments = Appointment::factory()->count(3)->create(['appointment_status_id' => $pending->id]);

    Livewire::test(ListAppointments::class)
        ->selectTableRecords($appointments->pluck('id')->toArray())
        ->callAction(TestAction::make('bulk_confirm')->table()->bulk())
        ->assertNotified();

    foreach ($appointments as $appointment) {
        expect($appointment->fresh()->status->name)->toBe('confirmed');
    }
});

test('bulk confirm skips non-pending appointments', function () {
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();

    $pendingAppt = Appointment::factory()->create(['appointment_status_id' => $pending->id]);
    $alreadyConfirmed = Appointment::factory()->create(['appointment_status_id' => $confirmed->id]);

    Livewire::test(ListAppointments::class)
        ->selectTableRecords([$pendingAppt->id, $alreadyConfirmed->id])
        ->callAction(TestAction::make('bulk_confirm')->table()->bulk())
        ->assertNotified();

    expect($pendingAppt->fresh()->status->name)->toBe('confirmed')
        ->and($alreadyConfirmed->fresh()->status->name)->toBe('confirmed');
});

// ─── Appointments: Bulk Cancel ────────────────────────────────────────────────

test('admin can bulk cancel pending appointments', function () {
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $appointments = Appointment::factory()->count(2)->create(['appointment_status_id' => $pending->id]);

    Livewire::test(ListAppointments::class)
        ->selectTableRecords($appointments->pluck('id')->toArray())
        ->callAction(TestAction::make('bulk_cancel')->table()->bulk())
        ->assertNotified();

    foreach ($appointments as $appointment) {
        expect($appointment->fresh()->status->name)->toBe('cancelled');
    }
});

// ─── Orders: Bulk Advance ────────────────────────────────────────────────────

test('bulk advance moves requested orders to confirmed', function () {
    $requested = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $orders = Order::factory()->count(2)->create([
        'order_status_id' => $requested->id,
        'is_non_prescription' => true,
    ]);

    Livewire::test(ListOrders::class)
        ->selectTableRecords($orders->pluck('id')->toArray())
        ->callAction(TestAction::make('bulk_advance')->table()->bulk())
        ->assertNotified();

    foreach ($orders as $order) {
        expect($order->fresh()->status->name)->toBe('confirmed');
    }
});

test('bulk advance skips terminal orders', function () {
    $completed = OrderStatus::query()->where('name', 'completed')->firstOrFail();
    $requested = OrderStatus::query()->where('name', 'requested')->firstOrFail();

    $completedOrder = Order::factory()->create(['order_status_id' => $completed->id]);
    $requestedOrder = Order::factory()->create([
        'order_status_id' => $requested->id,
        'is_non_prescription' => true,
    ]);

    Livewire::test(ListOrders::class)
        ->selectTableRecords([$completedOrder->id, $requestedOrder->id])
        ->callAction(TestAction::make('bulk_advance')->table()->bulk())
        ->assertNotified();

    expect($completedOrder->fresh()->status->name)->toBe('completed')
        ->and($requestedOrder->fresh()->status->name)->toBe('confirmed');
});

// ─── SMS: Bulk Retry ──────────────────────────────────────────────────────────

test('admin can bulk retry failed SMS notifications', function () {
    $failed = NotificationStatus::query()->where('name', 'failed')->firstOrFail();
    $sms = SmsNotification::factory()->count(2)->create([
        'notification_status_id' => $failed->id,
    ]);

    Livewire::test(ListSmsNotifications::class)
        ->selectTableRecords($sms->pluck('id')->toArray())
        ->callAction(TestAction::make('bulk_retry')->table()->bulk())
        ->assertNotified();

    foreach ($sms as $record) {
        expect($record->fresh()->status->name)->toBe('queued');
    }
});
