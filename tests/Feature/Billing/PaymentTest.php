<?php

use App\Actions\Billing\RecalculateBillingBalance;
use App\Models\Billing;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\PaymentStatus;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Database\Seeders\PaymentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);
    $this->seed(PaymentStatusSeeder::class);
});

// ─── RecalculateBillingBalance action ────────────────────────────────────────

it('posted payment reduces the billing balance_due', function () {
    $billing = Billing::factory()->issued()->create(['total_amount' => '300.00', 'balance_due' => '300.00']);
    $postedStatus = PaymentStatus::query()->where('name', 'posted')->firstOrFail();

    Payment::factory()->create([
        'billing_id' => $billing->id,
        'payment_status_id' => $postedStatus->id,
        'amount' => '100.00',
    ]);

    app(RecalculateBillingBalance::class)->handle($billing);

    $billing->refresh();

    expect($billing->amount_paid)->toBe('100.00')
        ->and($billing->balance_due)->toBe('200.00');
});

it('billing is marked paid when balance reaches zero', function () {
    $billing = Billing::factory()->issued()->create(['total_amount' => '150.00', 'balance_due' => '150.00']);
    $postedStatus = PaymentStatus::query()->where('name', 'posted')->firstOrFail();

    Payment::factory()->create([
        'billing_id' => $billing->id,
        'payment_status_id' => $postedStatus->id,
        'amount' => '150.00',
    ]);

    app(RecalculateBillingBalance::class)->handle($billing);

    $billing->refresh();

    expect($billing->balance_due)->toBe('0.00')
        ->and($billing->status->name)->toBe('paid');
});

it('billing is marked partially_paid when some balance remains', function () {
    $billing = Billing::factory()->issued()->create(['total_amount' => '200.00', 'balance_due' => '200.00']);
    $postedStatus = PaymentStatus::query()->where('name', 'posted')->firstOrFail();

    Payment::factory()->create([
        'billing_id' => $billing->id,
        'payment_status_id' => $postedStatus->id,
        'amount' => '80.00',
    ]);

    app(RecalculateBillingBalance::class)->handle($billing);

    $billing->refresh();

    expect($billing->balance_due)->toBe('120.00')
        ->and($billing->status->name)->toBe('partially_paid');
});

it('voided payment is excluded from balance calculation', function () {
    $billing = Billing::factory()->issued()->create(['total_amount' => '200.00', 'balance_due' => '200.00']);
    $postedStatus = PaymentStatus::query()->where('name', 'posted')->firstOrFail();
    $voidedStatus = PaymentStatus::query()->where('name', 'voided')->firstOrFail();

    Payment::factory()->create([
        'billing_id' => $billing->id,
        'payment_status_id' => $postedStatus->id,
        'amount' => '80.00',
    ]);

    Payment::factory()->create([
        'billing_id' => $billing->id,
        'payment_status_id' => $voidedStatus->id,
        'amount' => '50.00',
    ]);

    app(RecalculateBillingBalance::class)->handle($billing);

    $billing->refresh();

    expect($billing->amount_paid)->toBe('80.00')
        ->and($billing->balance_due)->toBe('120.00');
});

// ─── Customer API ─────────────────────────────────────────────────────────────

it('customers can view their own billing and payment history', function () {
    $customer = User::factory()->customer()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'order_status_id' => $confirmedStatus->id,
        'total_amount' => '250.00',
        'confirmed_at' => now(),
    ]);

    $billing = Billing::factory()->issued()->create([
        'order_id' => $order->id,
        'total_amount' => '250.00',
        'balance_due' => '250.00',
    ]);

    $postedStatus = PaymentStatus::query()->where('name', 'posted')->firstOrFail();
    Payment::factory()->create([
        'billing_id' => $billing->id,
        'payment_status_id' => $postedStatus->id,
        'amount' => '100.00',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/billing/{$billing->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $billing->id)
        ->assertJsonPath('data.total_amount', '250.00')
        ->assertJsonStructure([
            'data' => ['id', 'order_id', 'total_amount', 'amount_paid', 'balance_due', 'status', 'payments'],
        ]);
});

it('customers cannot view billings belonging to other customers', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();

    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create([
        'customer_id' => $otherCustomer->id,
        'order_status_id' => $confirmedStatus->id,
        'confirmed_at' => now(),
    ]);

    $billing = Billing::factory()->issued()->create(['order_id' => $order->id]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/billing/{$billing->id}")
        ->assertForbidden();
});
