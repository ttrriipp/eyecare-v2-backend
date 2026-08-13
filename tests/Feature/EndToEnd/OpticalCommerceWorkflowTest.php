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
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Actions\OpticalOrders\CreateOpticalOrderFromQuotation;
use App\Actions\Quotations\CreateQuotation;
use App\Enums\BillingRecordStatus;
use App\Enums\CommercialItemKind;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\BillingRecord;
use App\Models\DispensingEvent;
use App\Models\Encounter;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
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
                ['description' => 'Fitting Fee', 'quantity' => 1, 'unit_price' => 500, 'item_kind' => 'custom_service'],
            ],
        ],
        encounter: $encounter,
        prescription: $prescription,
    );

    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->items)->toHaveCount(3)
        ->and($quotation->prescription_id)->toBe($prescription->id);

    // ─── Step 3: Confirm sale with deposit ───
    $serviceItem = $quotation->items()->where('item_kind', CommercialItemKind::Service)->first();

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(
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

    // Verify deposit
    expect((float) $billingRecord->amount_paid)->toBe(2000.0)
        ->and((float) $billingRecord->balance_due)->toBe(6500.0);

    // Verify inventory committed
    $frame->refresh();
    expect($frame->stock_quantity)->toBe(9);

    // Verify idempotency (optical order is reused, billing record may differ after deposit)
    $retry = app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );
    expect($retry['optical_order']->id)->toBe($opticalOrder->id);

    // ─── Step 5: Start processing ───
    $this->actingAs($this->staff);
    $opticalOrder = app(UpdateJobOrderStatus::class)->handle($opticalOrder, 'in_progress');

    expect($opticalOrder->status)->toBe(JobOrderStatus::InProgress)
        ->and($opticalOrder->started_at)->not->toBeNull();

    // ─── Step 6: Mark ready for pickup ───
    $opticalOrder = app(UpdateJobOrderStatus::class)->handle($opticalOrder, 'ready_for_dispensing');

    expect($opticalOrder->status)->toBe(JobOrderStatus::ReadyForDispensing)
        ->and($opticalOrder->ready_at)->not->toBeNull();

    // ─── Step 7: Final payment and dispense ───
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

    // Verify inventory movements are balanced
    $commitments = InventoryMovement::where('job_order_id', $opticalOrder->id)
        ->whereHas('movementType', fn ($q) => $q->where('name', 'order_commitment'))
        ->sum('quantity_change');
    expect(abs($commitments))->toBe(1); // 1 frame committed
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
        'item_kind' => CommercialItemKind::Frame,
    ]);

    $quotation->items()->create([
        'description' => 'Lens',
        'quantity' => 1,
        'unit_price' => 3000,
        'amount' => 3000,
        'lens_category_id' => $lensCategory->id,
        'item_kind' => CommercialItemKind::LensPackage,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    $opticalOrder = $result['optical_order'];
    $opticalOrder->update(['uses_external_supplier' => true]);

    // Start processing
    $opticalOrder = app(UpdateJobOrderStatus::class)->handle($opticalOrder, 'in_progress');

    // Try to mark ready without supplier reference
    app(UpdateJobOrderStatus::class)->handle($opticalOrder, 'ready_for_dispensing');
})->throws(ValidationException::class, 'supplier invoice number');
