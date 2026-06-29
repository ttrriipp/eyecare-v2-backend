<?php

use App\Filament\Resources\Billings\Pages\ListBillings;
use App\Filament\Resources\Billings\Pages\ViewBilling;
use App\Filament\Resources\Billings\RelationManagers\PaymentsRelationManager;
use App\Models\AuditLog;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\DiscountType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Database\Seeders\PaymentMethodSeeder;
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
    $billing = Billing::factory()->issued()->create();

    $this->actingAs($staff);

    Livewire::test(ViewBilling::class, ['record' => $billing->getRouteKey()])
        ->assertSuccessful();
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
        ->callAction(TestAction::make('record_payment')->table(), data: [
            'amount' => 250.00,
            'payment_method_id' => $cashMethod->id,
            'paid_at' => now()->toDateTimeString(),
        ])
        ->assertNotified()
        ->assertHasNoActionErrors();

    $billing->refresh();
    expect((float) $billing->amount_paid)->toBe(250.0);
});

test('staff can void a payment via the payments table row action', function () {
    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->issued()->create(['total_amount' => '200.00', 'balance_due' => '200.00']);
    $payment = Payment::factory()->posted()->create([
        'billing_id' => $billing->id,
        'amount' => '200.00',
    ]);

    $paidStatus = BillingStatus::query()->where('name', 'paid')->firstOrFail();
    $billing->update(['amount_paid' => '200.00', 'balance_due' => '0.00', 'billing_status_id' => $paidStatus->id]);

    $this->actingAs($staff);

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $billing,
        'pageClass' => ViewBilling::class,
    ])
        ->callTableAction('void', $payment)
        ->assertNotified();

    $payment->refresh();
    $billing->refresh();

    expect($payment->status->name)->toBe('voided')
        ->and((float) $billing->balance_due)->toBe(200.0);
});

test('void_billing action voids the billing and all posted payments', function () {
    $this->seed(PaymentStatusSeeder::class);

    $admin = User::factory()->admin()->create();
    $billing = Billing::factory()->issued()->create([
        'total_amount' => '200.00',
        'balance_due' => '200.00',
    ]);

    $cashMethod = PaymentMethod::query()->firstOrCreate(['name' => 'Cash']);
    $postedStatus = PaymentStatus::query()->where('name', 'posted')->firstOrFail();

    $payment = Payment::factory()->create([
        'billing_id' => $billing->id,
        'payment_status_id' => $postedStatus->id,
        'amount' => '100.00',
        'payment_method_id' => $cashMethod->id,
    ]);

    $billing->update(['amount_paid' => '100.00', 'balance_due' => '100.00',
        'billing_status_id' => BillingStatus::query()->where('name', 'partially_paid')->value('id'),
    ]);

    $this->actingAs($admin);

    Livewire::test(ViewBilling::class, ['record' => $billing->getRouteKey()])
        ->callAction('void_billing')
        ->assertNotified();

    $billing->refresh();
    $payment->refresh();

    expect($billing->status->name)->toBe('voided')
        ->and($payment->status->name)->toBe('voided');

    // Audit log captures full billing state
    $auditLog = AuditLog::query()
        ->where('subject_type', Billing::class)
        ->where('subject_id', $billing->id)
        ->where('action', 'voided')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata['billing_number'])->toBe($billing->billing_number)
        ->and($auditLog->metadata['amount_paid'])->toBe('100.00')
        ->and($auditLog->metadata['payments_voided'])->toHaveCount(1)
        ->and($auditLog->metadata['payments_voided'][0]['amount'])->toBe('100.00');
});

test('apply_discount action updates billing totals', function () {
    $admin = User::factory()->admin()->create();
    $billing = Billing::factory()->issued()->create([
        'subtotal' => '800.00',
        'total_amount' => '800.00',
        'balance_due' => '800.00',
    ]);

    $this->actingAs($admin);

    // Senior Citizen 20% → 160.00 discount → 640.00 total
    $seniorType = DiscountType::query()->firstOrCreate(
        ['name' => 'Senior Citizen'],
        ['type' => 'percentage', 'value' => 20, 'is_active' => true]
    );

    Livewire::test(ViewBilling::class, ['record' => $billing->getRouteKey()])
        ->callAction('apply_discount', data: ['discount_type_id' => $seniorType->id])
        ->assertNotified()
        ->assertHasNoActionErrors();

    $billing->refresh();

    expect($billing->discount_type_id)->toBe($seniorType->id)
        ->and($billing->discount_amount)->toBe('160.00')
        ->and($billing->total_amount)->toBe('640.00')
        ->and($billing->balance_due)->toBe('640.00');
});
