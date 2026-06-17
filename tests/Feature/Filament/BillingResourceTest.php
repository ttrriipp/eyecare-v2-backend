<?php

use App\Filament\Resources\Billings\Pages\EditBilling;
use App\Filament\Resources\Billings\Pages\ListBillings;
use App\Filament\Resources\Billings\Pages\ViewBilling;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Database\Seeders\PaymentStatusSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);
    $this->seed(PaymentStatusSeeder::class);
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

test('staff can set due_date on a billing record', function () {
    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->draft()->create();
    $dueDate = now()->addDays(30)->toDateString();

    $this->actingAs($staff);

    Livewire::test(EditBilling::class, ['record' => $billing->getRouteKey()])
        ->fillForm(['due_date' => $dueDate])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Billing::class, [
        'id' => $billing->id,
        'due_date' => $dueDate,
    ]);
});

test('staff can record a payment on a billing via the view page action', function () {
    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->draft()->create(['total_amount' => '200.00', 'balance_due' => '200.00']);

    $this->actingAs($staff);

    Livewire::test(ViewBilling::class, ['record' => $billing->getRouteKey()])
        ->callAction('record_payment', data: [
            'amount' => 150.00,
            'method' => 'cash',
            'paid_at' => now()->toDateTimeString(),
        ])
        ->assertNotified()
        ->assertHasNoActionErrors();

    $billing->refresh();
    expect((float) $billing->amount_paid)->toBe(150.0)
        ->and((float) $billing->balance_due)->toBe(50.0);

    $this->assertDatabaseHas(Payment::class, [
        'billing_id' => $billing->id,
        'amount' => '150.00',
        'method' => 'cash',
    ]);
});

test('record payment action is hidden when billing is fully paid', function () {
    $staff = User::factory()->staff()->create();
    $paidStatus = BillingStatus::query()->where('name', 'paid')->firstOrFail();
    $billing = Billing::factory()->create([
        'billing_status_id' => $paidStatus->id,
        'total_amount' => '100.00',
        'amount_paid' => '100.00',
        'balance_due' => '0.00',
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewBilling::class, ['record' => $billing->getRouteKey()])
        ->assertActionHidden('record_payment');
});

test('staff can void a posted payment and billing balance recalculates', function () {
    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->draft()->create(['total_amount' => '200.00', 'balance_due' => '200.00']);
    $payment = Payment::factory()->posted()->create([
        'billing_id' => $billing->id,
        'amount' => '200.00',
    ]);

    // Manually set billing to match posted payment
    $paidStatus = BillingStatus::query()->where('name', 'paid')->firstOrFail();
    $billing->update(['amount_paid' => '200.00', 'balance_due' => '0.00', 'billing_status_id' => $paidStatus->id]);

    $this->actingAs($staff);

    Livewire::test(ViewBilling::class, ['record' => $billing->getRouteKey()])
        ->callAction(
            TestAction::make('void_payment')->arguments(['payment_id' => $payment->id]),
        )
        ->assertNotified();

    $payment->refresh();
    $billing->refresh();

    expect($payment->status->name)->toBe('voided')
        ->and((float) $billing->balance_due)->toBe(200.0);
});
