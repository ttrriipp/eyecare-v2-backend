<?php

use App\Enums\BillingRecordStatus;
use App\Filament\Resources\BillingRecords\Pages\EditBillingRecord;
use App\Filament\Resources\BillingRecords\Pages\ListBillingRecords;
use App\Filament\Resources\BillingRecords\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\BillingRecords\Widgets\BillingRecordStatsWidget;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\JobOrder;
use App\Models\Quotation;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('billing list displays its operational statistics', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(ListBillingRecords::class)
        ->assertSee('Balances Due')
        ->assertSee('Collected');
});

test('billing list statistics summarize balances, overdue records, paid records, and collections', function () {
    $staff = User::factory()->staff()->create();

    BillingRecord::factory()->create([
        'status' => BillingRecordStatus::Unpaid,
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
    ]);
    BillingRecord::factory()->partiallyPaid()->create([
        'total_amount' => 8000,
        'amount_paid' => 3000,
        'balance_due' => 5000,
        'payment_due_date' => today()->subDay(),
    ]);
    $paidRecord = BillingRecord::factory()->paid()->create(['total_amount' => 12000]);
    $voidedRecord = BillingRecord::factory()->voided()->create([
        'total_amount' => 9000,
        'balance_due' => 9000,
    ]);

    BillingPayment::factory()->create([
        'billing_record_id' => $paidRecord->id,
        'amount' => 12000,
        'status' => 'posted',
    ]);
    BillingPayment::factory()->reversed()->create([
        'billing_record_id' => $paidRecord->id,
        'amount' => 2000,
    ]);
    BillingPayment::factory()->create([
        'billing_record_id' => $paidRecord->id,
        'amount' => 1000,
        'status' => 'posted',
    ]);
    BillingPayment::factory()->create([
        'billing_record_id' => $voidedRecord->id,
        'amount' => 700,
        'status' => 'posted',
    ]);

    $this->actingAs($staff);

    $widget = Livewire::test(BillingRecordStatsWidget::class)->instance();
    $stats = collect((fn (): array => $this->getStats())->call($widget))->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats)->toHaveCount(4)
        ->and($stats->get('Balances Due')?->getValue())->toBe('2')
        ->and($stats->get('Overdue')?->getValue())->toBe('1')
        ->and($stats->get('Paid')?->getValue())->toBe('1')
        ->and($stats->get('Collected')?->getValue())->toBe('₱13,000.00');
});

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

test('billing record summary omits the derived status and missing quotation', function () {
    $staff = User::factory()->staff()->create();
    $billingRecord = BillingRecord::factory()->create([
        'payment_due_date' => today()->addDays(7),
    ]);

    $this->actingAs($staff);

    Livewire::test(EditBillingRecord::class, ['record' => $billingRecord->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Financial Summary')
        ->assertSee('Total Amount')
        ->assertSee('Amount Paid')
        ->assertSee('Balance Due')
        ->assertSee('Payment Due Date')
        ->assertSee('Unpaid')
        ->assertDontSee('Current')
        ->assertDontSee('Quotation');
});

test('billing review links to quotation and optical order', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create();
    $order = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'patient_id' => $quotation->patient_id,
    ]);
    $billingRecord = BillingRecord::factory()->create([
        'quotation_id' => $quotation->id,
        'job_order_id' => $order->id,
        'patient_id' => $quotation->patient_id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditBillingRecord::class, ['record' => $billingRecord->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($quotation->quotation_number)
        ->assertSee($order->job_order_number)
        ->assertActionVisible('viewQuotation')
        ->assertActionVisible('viewOpticalOrder');
});

test('staff can see itemized charges on a billing record', function () {
    $staff = User::factory()->staff()->create();
    $billingRecord = BillingRecord::factory()->create(['total_amount' => 12500]);
    BillingRecordItem::factory()->product()->create([
        'billing_record_id' => $billingRecord->id,
        'description' => 'Complete Frame and Single Vision Lens',
        'quantity' => 1,
        'unit_price' => 12500,
        'amount' => 12500,
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
            'charges_reviewed' => true,
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
