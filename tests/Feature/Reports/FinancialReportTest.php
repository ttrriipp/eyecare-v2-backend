<?php

use App\Enums\BillingRecordStatus;
use App\Filament\Clusters\Reports\Pages\FinancialReport;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('financial report uses the bill cohort and excludes voided, reversed, and out-of-period records', function () {
    $this->travelTo(Carbon::parse('2026-08-30 10:15:00', 'Asia/Manila'));

    $includedPartial = BillingRecord::factory()->create([
        'status' => BillingRecordStatus::PartiallyPaid,
        'subtotal_amount' => 1000,
        'discount_amount' => 100,
        'total_amount' => 900,
        'amount_paid' => 200,
        'balance_due' => 700,
        'recorded_at' => '2026-08-01 00:00:00',
    ]);
    $includedUnpaid = BillingRecord::factory()->create([
        'status' => BillingRecordStatus::Unpaid,
        'subtotal_amount' => 550,
        'discount_amount' => 50,
        'total_amount' => 500,
        'amount_paid' => 0,
        'balance_due' => 500,
        'recorded_at' => '2026-08-30 23:59:59',
    ]);
    $voided = BillingRecord::factory()->voided()->create([
        'total_amount' => 1000,
        'discount_amount' => 100,
        'recorded_at' => '2026-08-10 12:00:00',
    ]);
    BillingRecord::factory()->create([
        'total_amount' => 700,
        'discount_amount' => 70,
        'recorded_at' => '2026-07-31 23:59:59',
    ]);
    BillingRecord::factory()->create([
        'total_amount' => 800,
        'discount_amount' => 80,
        'recorded_at' => '2026-08-31 00:00:00',
    ]);

    BillingPayment::factory()->create([
        'billing_record_id' => $includedPartial->id,
        'amount' => 200,
        'payment_method' => 'cash',
        'recorded_at' => '2026-08-15 12:00:00',
    ]);
    BillingPayment::factory()->reversed()->create([
        'billing_record_id' => $includedPartial->id,
        'amount' => 50,
        'payment_method' => 'cash',
        'recorded_at' => '2026-08-15 13:00:00',
    ]);
    BillingPayment::factory()->create([
        'billing_record_id' => $includedUnpaid->id,
        'amount' => 300,
        'payment_method' => 'gcash',
        'recorded_at' => '2026-08-30 23:59:59',
    ]);
    BillingPayment::factory()->create([
        'billing_record_id' => $includedUnpaid->id,
        'amount' => 400,
        'payment_method' => 'card',
        'recorded_at' => '2026-08-31 00:00:00',
    ]);
    BillingPayment::factory()->create([
        'billing_record_id' => $voided->id,
        'amount' => 999,
        'payment_method' => 'bank_transfer',
        'recorded_at' => '2026-08-20 12:00:00',
    ]);

    BillingRecordItem::factory()->service()->create([
        'billing_record_id' => $includedPartial->id,
        'amount' => 800,
    ]);
    BillingRecordItem::factory()->product()->create([
        'billing_record_id' => $includedUnpaid->id,
        'amount' => 600,
    ]);
    BillingRecordItem::factory()->service()->create([
        'billing_record_id' => $voided->id,
        'amount' => 999,
    ]);

    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::withQueryParams([
        'dateFrom' => '2026-08-01',
        'dateUntil' => '2026-08-30',
    ])->test(FinancialReport::class);
    $stats = collect($component->instance()->getStats())->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats->get('Net billed')?->getValue())
        ->toBe('₱1,400.00')
        ->and($stats->get('Discounts')?->getValue())
        ->toBe('₱150.00')
        ->and($stats->get('Collections')?->getValue())
        ->toBe('₱500.00')
        ->and($stats->get('Open balance (snapshot)')?->getValue())
        ->toBe('₱1,200.00');

    $sections = collect($component->instance()->getSections())->keyBy('title');

    expect($sections->get('Bill status')['rows'])
        ->toMatchArray([
            ['label' => 'Unpaid', 'value' => 1, 'percentage' => 50],
            ['label' => 'Partially Paid', 'value' => 1, 'percentage' => 50],
            ['label' => 'Paid', 'value' => 0, 'percentage' => 0],
        ])
        ->and($sections->get('Payment method')['rows'])
        ->toMatchArray([
            ['label' => 'Cash', 'value' => '₱200.00', 'percentage' => 40],
            ['label' => 'Gcash', 'value' => '₱300.00', 'percentage' => 60],
        ])
        ->and($sections->get('Charge source')['rows'])
        ->toMatchArray([
            ['label' => 'Optical Order', 'value' => '₱600.00', 'percentage' => 43],
            ['label' => 'Direct Charge', 'value' => '₱800.00', 'percentage' => 57],
        ]);
});

test('financial report never uses the optical order total as billed revenue', function () {
    $this->travelTo(Carbon::parse('2026-08-15 10:00:00', 'Asia/Manila'));

    $billingRecord = BillingRecord::factory()->create([
        'total_amount' => 300,
        'recorded_at' => '2026-08-15 10:00:00',
    ]);
    $billingRecord->jobOrder->update(['total_amount' => 99999]);

    $this->actingAs(User::factory()->admin()->create());

    $stats = collect(Livewire::test(FinancialReport::class)->instance()->getStats())->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats->get('Net billed')?->getValue())->toBe('₱300.00');
});
