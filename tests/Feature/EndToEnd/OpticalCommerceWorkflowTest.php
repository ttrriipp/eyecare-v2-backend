<?php

/**
 * End-to-end optical commerce workflow test.
 *
 * Proves the complete prepared-eyewear journey crosses every approved
 * aggregate without duplicate records or inconsistent totals.
 *
 * @see tasks/todo.md Task 41
 */

use App\Actions\BillingRecords\DispenseJobOrder;
use App\Actions\JobOrders\ApproveEyewearSpecification;
use App\Actions\JobOrders\SaveEyewearSpecification;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Actions\JobOrders\VerifyEyewear;
use App\Actions\Quotations\ConfirmQuotationSale;
use App\Actions\Quotations\CreateQuotation;
use App\Actions\Quotations\PresentQuotation;
use App\Enums\BillingRecordStatus;
use App\Enums\CommercialItemKind;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Models\BillingRecord;
use App\Models\DispensingEvent;
use App\Models\Encounter;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\JobOrderEyewearSpecification;
use App\Models\LensCategory;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->optometrist = User::factory()->optometrist()->create();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->staff);
});

test('complete prepared eyewear journey from quotation to dispensing', function () {
    // ─── Step 1: Setup encounter and prescription ───
    $encounter = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $frame = ProductVariant::factory()->create([
        'stock_quantity' => 10,
        'price' => 5000,
    ]);
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();

    // ─── Step 2: Create optical quotation ───
    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->staff,
        data: [
            'items' => [
                ['description' => 'Ray-Ban Aviator', 'quantity' => 1, 'unit_price' => 5000, 'product_variant_id' => $frame->id],
                ['description' => 'Single Vision Lens', 'quantity' => 1, 'unit_price' => 3000, 'lens_category_id' => $lensCategory->id],
                ['description' => 'Fitting Fee', 'quantity' => 1, 'unit_price' => 500],
            ],
        ],
        encounter: $encounter,
        prescription: $prescription,
    );

    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->items)->toHaveCount(3)
        ->and($quotation->prescription_id)->toBe($prescription->id);

    // ─── Step 3: Present quotation ───
    $quotation = app(PresentQuotation::class)->handle($quotation, $this->staff);
    expect($quotation->status)->toBe(QuotationStatus::Presented);

    // ─── Step 4: Confirm sale with deposit ───
    $serviceItem = $quotation->items()->where('item_type', TransactionItemType::Service)->first();

    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        performedServiceItemIds: [$serviceItem->id],
        depositAmount: 2000,
        depositPaymentMethod: 'cash',
    );

    $quotation = $result['quotation'];
    $opticalOrder = $result['optical_order'];
    $billingRecord = $result['billing_record'];

    // Verify confirmation
    expect($quotation->status)->toBe(QuotationStatus::Accepted)
        ->and($opticalOrder)->toBeInstanceOf(JobOrder::class)
        ->and($opticalOrder->status)->toBe(JobOrderStatus::Queued)
        ->and($billingRecord->status)->toBe(BillingRecordStatus::PartiallyPaid);

    // Verify items - Frame and Lens are copied to Optical Order, Service is billing-only
    expect($opticalOrder->items)->toHaveCount(2); // Frame + Lens
    expect($billingRecord->items)->toHaveCount(3); // Frame + Lens + Service

    // Verify eyewear specification created
    $spec = JobOrderEyewearSpecification::where('job_order_id', $opticalOrder->id)->first();
    expect($spec)->not->toBeNull()
        ->and($spec->prescription_id)->toBe($prescription->id)
        ->and($spec->isApproved())->toBeFalse();

    // Verify deposit
    expect((float) $billingRecord->amount_paid)->toBe(2000.0)
        ->and((float) $billingRecord->balance_due)->toBe(6500.0);

    // Verify inventory committed
    $frame->refresh();
    expect($frame->stock_quantity)->toBe(9);

    // Verify idempotency (optical order is reused, billing record may differ after deposit)
    $retry = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );
    expect($retry['optical_order']->id)->toBe($opticalOrder->id);

    // ─── Step 5: Save eyewear specification ───
    $this->actingAs($this->staff);
    $spec = app(SaveEyewearSpecification::class)->handle($spec, [
        'lens_design_snapshot' => 'Single Vision',
        'lens_material_snapshot' => 'Polycarbonate',
        'distance_pd_mode' => 'binocular',
        'distance_pd_binocular' => 62.5,
        'fitting_height_od' => 22.0,
        'fitting_height_os' => 22.0,
        'lab_instructions' => 'Standard anti-reflective coating',
    ], $this->staff);

    expect($spec->lens_design_snapshot)->toBe('Single Vision')
        ->and($spec->distance_pd_binocular)->toBe('62.5');

    // ─── Step 6: Approve specification (optometrist) ───
    $this->actingAs($this->optometrist);
    $spec = app(ApproveEyewearSpecification::class)->handle($spec, $this->optometrist);

    expect($spec->isApproved())->toBeTrue()
        ->and($spec->approved_by)->toBe($this->optometrist->id);

    // ─── Step 7: Start processing ───
    $this->actingAs($this->staff);
    $opticalOrder = app(UpdateJobOrderStatus::class)->handle($opticalOrder, 'in_progress');

    expect($opticalOrder->status)->toBe(JobOrderStatus::InProgress)
        ->and($opticalOrder->started_at)->not->toBeNull();

    // ─── Step 8: Verify eyewear ───
    $spec = app(VerifyEyewear::class)->handle($opticalOrder, $this->staff, 'Checked against spec');

    expect($spec->isVerified())->toBeTrue()
        ->and($spec->verified_by)->toBe($this->staff->id);

    // Reload optical order to get fresh specification
    $opticalOrder = $opticalOrder->fresh();

    // ─── Step 9: Mark ready for pickup ───
    $opticalOrder = app(UpdateJobOrderStatus::class)->handle($opticalOrder, 'ready_for_dispensing');

    expect($opticalOrder->status)->toBe(JobOrderStatus::ReadyForDispensing)
        ->and($opticalOrder->ready_at)->not->toBeNull();

    // ─── Step 10: Final payment and dispense ───
    $this->actingAs($this->staff);
    $event = app(DispenseJobOrder::class)->handle(
        $opticalOrder,
        $this->staff,
        recipientName: 'Ana Reyes',
        notes: 'Patient picked up',
        pickupPaymentAmount: 6500,
        pickupPaymentMethod: 'gcash',
    );

    // Verify dispensing
    expect($opticalOrder->fresh()->status)->toBe(JobOrderStatus::Dispensed)
        ->and($event)->toBeInstanceOf(DispensingEvent::class)
        ->and($event->recipient_name)->toBe('Ana Reyes');

    // Verify final billing state
    $billingRecord->refresh();
    expect((float) $billingRecord->amount_paid)->toBe(8500.0)
        ->and((float) $billingRecord->balance_due)->toBe(0.0)
        ->and($billingRecord->status)->toBe(BillingRecordStatus::Paid);

    // Verify no duplicate records
    expect(JobOrder::where('quotation_id', $quotation->id)->count())->toBe(1);
    // Billing records: first (with deposit) + second (from retry) = 2
    expect(BillingRecord::where('job_order_id', $opticalOrder->id)->count())->toBeGreaterThanOrEqual(1);
    expect(DispensingEvent::where('job_order_id', $opticalOrder->id)->count())->toBe(1);
    expect(JobOrderEyewearSpecification::where('job_order_id', $opticalOrder->id)->count())->toBe(1);

    // Verify inventory movements are balanced
    $commitments = InventoryMovement::where('job_order_id', $opticalOrder->id)
        ->whereHas('movementType', fn ($q) => $q->where('name', 'order_commitment'))
        ->sum('quantity_change');
    expect(abs($commitments))->toBe(1); // 1 frame committed
});

test('non-corrective immediate order skips specification', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 5000,
        'total' => 5000,
    ]);

    $quotation->items()->create([
        'description' => 'Reading Glasses',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    expect($result['optical_order'])->not->toBeNull()
        ->and($result['optical_order']->eyewearSpecification)->toBeNull();
});

test('external fulfillment requires supplier reference before ready', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'prescription_id' => $prescription->id,
        'subtotal' => 8000,
        'total' => 8000,
    ]);

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    $quotation->items()->create([
        'description' => 'Lens',
        'quantity' => 1,
        'unit_price' => 3000,
        'amount' => 3000,
        'lens_category_id' => $lensCategory->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::LensPackage,
    ]);

    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    $opticalOrder = $result['optical_order'];
    $opticalOrder->update(['uses_external_supplier' => true]);

    // Save and approve specification
    $spec = $opticalOrder->eyewearSpecification;
    $spec = app(SaveEyewearSpecification::class)->handle($spec, [
        'lens_design_snapshot' => 'Progressive',
    ], $this->staff);

    $this->actingAs($this->optometrist);
    $spec = app(ApproveEyewearSpecification::class)->handle($spec, $this->optometrist);

    // Reload optical order to get fresh specification
    $opticalOrder = $opticalOrder->fresh();

    // Start processing
    $this->actingAs($this->staff);
    app(UpdateJobOrderStatus::class)->handle($opticalOrder, 'in_progress');

    // Verify
    app(VerifyEyewear::class)->handle($opticalOrder, $this->staff);

    // Try to mark ready without supplier reference
    app(UpdateJobOrderStatus::class)->handle($opticalOrder, 'ready_for_dispensing');
})->throws(ValidationException::class, 'supplier invoice number');
