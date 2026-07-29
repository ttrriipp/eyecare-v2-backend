<?php

use App\Enums\BillingRecordStatus;
use App\Filament\Resources\BillingRecords\Pages\EditBillingRecord;
use App\Filament\Resources\BillingRecords\RelationManagers\PaymentsRelationManager;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can view a billing record', function () {
    $staff = User::factory()->staff()->create();
    $billingRecord = BillingRecord::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditBillingRecord::class, ['record' => $billingRecord->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($billingRecord->billing_record_number)
        ->assertSeeLivewire(PaymentsRelationManager::class)
        ->assertActionDoesNotExist('recordPayment')
        ->assertActionDoesNotExist('correctPayment');
});

test('staff can see linked job order items on a billing record', function () {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create();
    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'description' => 'Complete Frame and Single Vision Lens',
        'quantity' => 1,
        'unit_price' => 12500,
        'amount' => 12500,
    ]);
    $billingRecord = BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 12500,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditBillingRecord::class, ['record' => $billingRecord->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Complete Frame and Single Vision Lens')
        ->assertSee('12,500.00');
});

test('payments relation manager lists only the billing records payments without direct crud actions', function () {
    $staff = User::factory()->staff()->create();
    $billingRecord = BillingRecord::factory()->create();
    $payments = BillingPayment::factory()->count(2)->create([
        'billing_record_id' => $billingRecord->id,
    ]);
    $otherPayment = BillingPayment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $billingRecord,
        'pageClass' => EditBillingRecord::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($payments)
        ->assertCanNotSeeTableRecords([$otherPayment])
        ->assertActionDoesNotExist(TestAction::make('create')->table())
        ->assertActionDoesNotExist(TestAction::make('edit')->table($payments->first()))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($payments->first()));
});

test('staff records a payment through the payments relation manager', function () {
    $staff = User::factory()->staff()->create();
    $billingRecord = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    $this->actingAs($staff);

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $billingRecord,
        'pageClass' => EditBillingRecord::class,
    ])
        ->callAction(TestAction::make('recordPayment')->table(), [
            'amount' => 2000,
            'payment_method' => 'cash',
            'reference_number' => 'OR-100',
            'notes' => 'Down payment',
        ])
        ->assertNotified();

    $billingRecord->refresh();

    expect($billingRecord->payments)->toHaveCount(1)
        ->and($billingRecord->payments->first()->reference_number)->toBe('OR-100')
        ->and($billingRecord->amount_paid)->toBe('2000.00')
        ->and($billingRecord->balance_due)->toBe('3000.00');
});

test('only admins can correct a posted payment through its table row', function () {
    $billingRecord = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 2000,
        'balance_due' => 3000,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);
    $payment = BillingPayment::factory()->create([
        'billing_record_id' => $billingRecord->id,
        'amount' => 2000,
    ]);

    $this->actingAs(User::factory()->staff()->create());

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $billingRecord,
        'pageClass' => EditBillingRecord::class,
    ])->assertActionHidden(TestAction::make('correctPayment')->table($payment));

    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $billingRecord,
        'pageClass' => EditBillingRecord::class,
    ])
        ->callAction(TestAction::make('correctPayment')->table($payment), [
            'new_amount' => 1500,
            'reference_number' => 'COR-100',
            'reason' => 'Incorrect amount entered',
        ])
        ->assertNotified();

    $payment->refresh();
    $billingRecord->refresh();

    expect($payment->status)->toBe('reversed')
        ->and($payment->reversal_reason)->toBe('Incorrect amount entered')
        ->and($billingRecord->payments()->where('status', 'posted')->value('amount'))->toBe('1500.00')
        ->and($billingRecord->amount_paid)->toBe('1500.00')
        ->and($billingRecord->balance_due)->toBe('3500.00');
});
