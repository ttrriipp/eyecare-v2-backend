<?php

use App\Filament\Resources\Billings\Pages\ListBillings;
use App\Filament\Resources\Billings\Pages\ViewBilling;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);
});

test('staff and admin users can list billings', function (string $factoryState) {
    $user = User::factory()->{$factoryState}()->create();
    $billings = Billing::factory()->count(2)->create();

    $this->actingAs($user);

    Livewire::test(ListBillings::class)
        ->assertCanSeeTableRecords($billings);
})->with([
    'admin' => ['admin'],
    'staff' => ['staff'],
]);

test('staff can view a billing record', function () {
    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->draft()->create();

    $this->actingAs($staff);

    Livewire::test(ViewBilling::class, ['record' => $billing->getRouteKey()])
        ->assertSuccessful();
});

test('staff can generate billing from a confirmed order via the list page action', function () {
    $staff = User::factory()->staff()->create();

    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
        'total_amount' => '250.00',
        'confirmed_at' => now(),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListBillings::class)
        ->callAction('generate_billing', data: ['order_id' => $order->id])
        ->assertNotified()
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas(Billing::class, [
        'order_id' => $order->id,
        'total_amount' => '250.00',
        'balance_due' => '250.00',
    ]);
});

test('duplicate billing generation is blocked with a validation error', function () {
    $staff = User::factory()->staff()->create();

    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $draftStatus = BillingStatus::query()->where('name', 'draft')->firstOrFail();

    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
        'total_amount' => '150.00',
        'confirmed_at' => now(),
    ]);

    Billing::factory()->create([
        'order_id' => $order->id,
        'billing_status_id' => $draftStatus->id,
        'total_amount' => '150.00',
        'balance_due' => '150.00',
    ]);

    $this->actingAs($staff);

    Livewire::test(ListBillings::class)
        ->callAction('generate_billing', data: ['order_id' => $order->id])
        ->assertHasActionErrors(['order_id']);

    expect(Billing::where('order_id', $order->id)->count())->toBe(1);
});

test('billing generation is blocked for non-confirmed orders', function () {
    $staff = User::factory()->staff()->create();

    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'order_status_id' => $requestedStatus->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ListBillings::class)
        ->callAction('generate_billing', data: ['order_id' => $order->id])
        ->assertHasActionErrors(['order_id']);

    expect(Billing::where('order_id', $order->id)->count())->toBe(0);
});
