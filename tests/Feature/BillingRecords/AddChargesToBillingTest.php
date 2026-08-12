<?php

use App\Actions\BillingRecords\AddChargesToBilling;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Enums\BillingItemSourceKind;
use App\Enums\BillingRecordStatus;
use App\Enums\TransactionItemType;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Patient;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->action = app(AddChargesToBilling::class);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('optical order source kind appends items and recalculates totals', function (): void {
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create(['patient_id' => $patient->id]);
    $item = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'item_type' => TransactionItemType::Product,
    ]);

    $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
        patient: $patient,
        jobOrder: $jobOrder,
    );

    $this->action->handle(
        billingRecord: $billingRecord,
        sourceKind: BillingItemSourceKind::OpticalOrder,
        items: collect([[
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'amount' => $item->amount,
            'item_type' => $item->item_type,
            'job_order_item_id' => $item->id,
        ]]),
    );

    $billingRecord->refresh();
    expect($billingRecord->items)->toHaveCount(1);
    expect(number_format((float) $billingRecord->subtotal_amount, 2))->toBe(number_format((float) $item->amount, 2));
});

test('quotation source kind appends service items', function (): void {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create(['patient_id' => $patient->id]);
    $item = QuotationItem::factory()->create([
        'quotation_id' => $quotation->id,
        'item_type' => TransactionItemType::Service,
    ]);

    $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
        patient: $patient,
    );

    $this->action->handle(
        billingRecord: $billingRecord,
        sourceKind: BillingItemSourceKind::Quotation,
        items: collect([[
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'amount' => $item->amount,
            'item_type' => $item->item_type,
            'quotation_item_id' => $item->id,
        ]]),
    );

    $billingRecord->refresh();
    expect($billingRecord->items)->toHaveCount(1);
    expect($billingRecord->items->first()->source_kind)->toBe(BillingItemSourceKind::Quotation);
});

test('encounter source kind appends items with encounter_id', function (): void {
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create(['patient_id' => $patient->id]);

    $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
        patient: $patient,
        encounter: $encounter,
    );

    $this->action->handle(
        billingRecord: $billingRecord,
        sourceKind: BillingItemSourceKind::Encounter,
        items: collect([[
            'description' => 'Eye Exam',
            'quantity' => 1,
            'unit_price' => '500.00',
            'amount' => '500.00',
            'item_type' => TransactionItemType::Service,
            'encounter_id' => $encounter->id,
        ]]),
    );

    $billingRecord->refresh();
    expect($billingRecord->items)->toHaveCount(1);
    expect($billingRecord->items->first()->encounter_id)->toBe($encounter->id);
    expect($billingRecord->items->first()->source_kind)->toBe(BillingItemSourceKind::Encounter);
});

test('direct service source kind appends items without entity reference', function (): void {
    $patient = Patient::factory()->create();

    $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
        patient: $patient,
    );

    $this->action->handle(
        billingRecord: $billingRecord,
        sourceKind: BillingItemSourceKind::DirectService,
        items: collect([[
            'description' => 'Consultation',
            'quantity' => 1,
            'unit_price' => '300.00',
            'amount' => '300.00',
            'item_type' => TransactionItemType::Service,
        ]]),
    );

    $billingRecord->refresh();
    expect($billingRecord->items)->toHaveCount(1);
    expect($billingRecord->items->first()->source_kind)->toBe(BillingItemSourceKind::DirectService);
});

test('recalculates totals after appending items', function (): void {
    $patient = Patient::factory()->create();
    $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
        patient: $patient,
    );

    $this->action->handle(
        billingRecord: $billingRecord,
        sourceKind: BillingItemSourceKind::DirectService,
        items: collect([
            [
                'description' => 'Service A',
                'quantity' => 1,
                'unit_price' => '500.00',
                'amount' => '500.00',
                'item_type' => TransactionItemType::Service,
            ],
            [
                'description' => 'Service B',
                'quantity' => 2,
                'unit_price' => '300.00',
                'amount' => '600.00',
                'item_type' => TransactionItemType::Service,
            ],
        ]),
        discountAmount: 100.00,
    );

    $billingRecord->refresh();
    expect($billingRecord->items)->toHaveCount(2);
    expect((float) $billingRecord->subtotal_amount)->toBeGreaterThan(1099.0);
    expect((float) $billingRecord->subtotal_amount)->toBeLessThan(1101.0);
    expect((float) $billingRecord->discount_amount)->toBe(100.0);
    expect((float) $billingRecord->total_amount)->toBeGreaterThan(999.0);
    expect((float) $billingRecord->total_amount)->toBeLessThan(1001.0);
    expect($billingRecord->status)->toBe(BillingRecordStatus::Unpaid);
});

test('rejects appending when posted payments exist', function (): void {
    $patient = Patient::factory()->create();
    $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
        patient: $patient,
    );

    // Add an item first so we can record a payment
    $this->action->handle(
        billingRecord: $billingRecord,
        sourceKind: BillingItemSourceKind::DirectService,
        items: collect([[
            'description' => 'Initial charge',
            'quantity' => 1,
            'unit_price' => '200.00',
            'amount' => '200.00',
            'item_type' => TransactionItemType::Service,
        ]]),
    );

    // Record a payment (this finalizes the charge set)
    app(RecordBillingPayment::class)->handle(
        billingRecord: $billingRecord->fresh(),
        amount: 200.00,
        paymentMethod: 'cash',
        recorder: $this->user,
        chargesReviewed: true,
    );

    $this->expectException(ValidationException::class);

    $this->action->handle(
        billingRecord: $billingRecord->fresh(),
        sourceKind: BillingItemSourceKind::DirectService,
        items: collect([[
            'description' => 'Late charge',
            'quantity' => 1,
            'unit_price' => '50.00',
            'amount' => '50.00',
            'item_type' => TransactionItemType::Service,
        ]]),
    );
});

test('empty items collection is a no-op', function (): void {
    $patient = Patient::factory()->create();
    $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
        patient: $patient,
    );

    $this->action->handle(
        billingRecord: $billingRecord,
        sourceKind: BillingItemSourceKind::DirectService,
        items: collect(),
    );

    $billingRecord->refresh();
    expect($billingRecord->items)->toHaveCount(0);
});
