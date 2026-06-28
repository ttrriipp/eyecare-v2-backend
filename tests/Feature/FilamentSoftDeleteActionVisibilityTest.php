<?php

use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(OrderStatusSeeder::class);
    $this->actingAs(User::factory()->admin()->create());
});

// --- Appointments Table ---

test('appointment table shows delete and hides restore/force-delete for non-deleted record', function () {
    $appointment = Appointment::factory()->create();

    Livewire::test(ListAppointments::class)
        ->assertTableActionVisible('delete', $appointment)
        ->assertTableActionHidden('restore', $appointment)
        ->assertTableActionHidden('forceDelete', $appointment);
});

test('appointment table hides delete and shows restore/force-delete for soft-deleted record', function () {
    $appointment = Appointment::factory()->create();
    $appointment->delete();

    Livewire::test(ListAppointments::class)
        ->assertTableActionHidden('delete', $appointment)
        ->assertTableActionVisible('restore', $appointment)
        ->assertTableActionVisible('forceDelete', $appointment);
});

// --- Orders Table ---

test('order table shows delete and hides restore/force-delete for non-deleted record', function () {
    $order = Order::factory()->create();

    Livewire::test(ListOrders::class)
        ->assertTableActionVisible('delete', $order)
        ->assertTableActionHidden('restore', $order)
        ->assertTableActionHidden('forceDelete', $order);
});

test('order table hides delete and shows restore/force-delete for soft-deleted record', function () {
    $order = Order::factory()->create();
    $order->delete();

    Livewire::test(ListOrders::class)
        ->assertTableActionHidden('delete', $order)
        ->assertTableActionVisible('restore', $order)
        ->assertTableActionVisible('forceDelete', $order);
});

// --- Products Table ---

test('product table shows delete and hides restore/force-delete for non-deleted record', function () {
    $product = Product::factory()->create();

    Livewire::test(ListProducts::class)
        ->assertTableActionVisible('delete', $product)
        ->assertTableActionHidden('restore', $product)
        ->assertTableActionHidden('forceDelete', $product);
});

test('product table hides delete and shows restore/force-delete for soft-deleted record', function () {
    $product = Product::factory()->create();
    $product->delete();

    Livewire::test(ListProducts::class)
        ->assertTableActionHidden('delete', $product)
        ->assertTableActionVisible('restore', $product)
        ->assertTableActionVisible('forceDelete', $product);
});

// --- EditOrder Header ---

test('edit order page shows delete and hides restore/force-delete for non-deleted order', function () {
    $order = Order::factory()->create();

    Livewire::test(EditOrder::class, ['record' => $order->id])
        ->assertActionVisible(DeleteAction::class)
        ->assertActionHidden(RestoreAction::class)
        ->assertActionHidden(ForceDeleteAction::class);
});

test('edit order page hides delete and shows restore/force-delete for soft-deleted order', function () {
    $order = Order::factory()->create();
    $order->delete();

    Livewire::test(EditOrder::class, ['record' => $order->id])
        ->assertActionHidden(DeleteAction::class)
        ->assertActionVisible(RestoreAction::class)
        ->assertActionVisible(ForceDeleteAction::class);
});

// --- EditProduct Header ---

test('edit product page shows delete and hides restore/force-delete for non-deleted product', function () {
    $product = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->assertActionVisible(DeleteAction::class)
        ->assertActionHidden(RestoreAction::class)
        ->assertActionHidden(ForceDeleteAction::class);
});

test('edit product page hides delete and shows restore/force-delete for soft-deleted product', function () {
    $product = Product::factory()->create();
    $product->delete();

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->assertActionHidden(DeleteAction::class)
        ->assertActionVisible(RestoreAction::class)
        ->assertActionVisible(ForceDeleteAction::class);
});
