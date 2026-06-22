<?php

use App\Filament\Resources\Billings\Pages\ListBillings;
use App\Filament\Resources\Billings\Pages\ViewBilling;
use App\Filament\Resources\Billings\RelationManagers\PaymentsRelationManager;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\PaymentStatusSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
    $billing = Billing::factory()->issued()->create();

    $this->actingAs($staff);

    Livewire::test(ViewBilling::class, ['record' => $billing->getRouteKey()])
        ->assertSuccessful();
});

test('staff can record a payment on a billing via the view page action', function () {
    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->issued()->create(['total_amount' => '200.00', 'balance_due' => '200.00']);
    $cashMethod = PaymentMethod::query()->firstOrCreate(['name' => 'Cash']);

    $this->actingAs($staff);

    Livewire::test(ViewBilling::class, ['record' => $billing->getRouteKey()])
        ->callAction('record_payment', data: [
            'amount' => 150.00,
            'payment_method_id' => $cashMethod->id,
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
        'payment_method_id' => $cashMethod->id,
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
    $billing = Billing::factory()->issued()->create(['total_amount' => '200.00', 'balance_due' => '200.00']);
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

test('void payment action cannot target a payment from a different billing', function () {
    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->issued()->create(['total_amount' => '100.00', 'balance_due' => '100.00']);
    $otherBilling = Billing::factory()->issued()->create(['total_amount' => '50.00', 'balance_due' => '50.00']);
    $otherPayment = Payment::factory()->posted()->create(['billing_id' => $otherBilling->id, 'amount' => '50.00']);

    $this->actingAs($staff);

    $this->expectException(ModelNotFoundException::class);

    Livewire::test(ViewBilling::class, ['record' => $billing->getRouteKey()])
        ->callAction(
            TestAction::make('void_payment')->arguments(['payment_id' => $otherPayment->id]),
        );
});

test('payments table renders on billing view page', function () {
    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->issued()->create(['total_amount' => '300.00', 'balance_due' => '300.00']);
    $cashMethod = PaymentMethod::query()->firstOrCreate(['name' => 'Cash']);
    $postedStatus = PaymentStatus::query()->where('name', 'posted')->firstOrFail();

    Payment::factory()->create([
        'billing_id' => $billing->id,
        'payment_status_id' => $postedStatus->id,
        'payment_method_id' => $cashMethod->id,
        'amount' => '150.00',
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewBilling::class, ['record' => $billing->getRouteKey()])
        ->assertSuccessful();
});

test('staff can record a payment via the payments relation manager', function () {
    $this->seed(PaymentStatusSeeder::class);
    $this->seed(PaymentMethodSeeder::class);

    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->issued()->create(['total_amount' => '500.00', 'balance_due' => '500.00']);
    $cashMethod = PaymentMethod::query()->firstOrCreate(['name' => 'Cash']);

    $this->actingAs($staff);

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $billing,
        'pageClass' => ViewBilling::class,
    ])
        ->callAction('record_payment', data: [
            'amount' => 250.00,
            'payment_method_id' => $cashMethod->id,
            'paid_at' => now()->toDateTimeString(),
        ])
        ->assertNotified()
        ->assertHasNoActionErrors();

    $billing->refresh();
    expect((float) $billing->amount_paid)->toBe(250.0);
});
