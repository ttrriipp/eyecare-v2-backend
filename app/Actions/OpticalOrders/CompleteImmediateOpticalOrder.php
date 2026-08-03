<?php

namespace App\Actions\OpticalOrders;

use App\Actions\BillingRecords\AppendJobOrderItemsToBillingRecord;
use App\Actions\BillingRecords\RecalculateBillingRecordTotals;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Enums\BillingItemSourceKind;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\DispensingEvent;
use App\Models\JobOrder;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteImmediateOpticalOrder
{
    /**
     * Complete an immediate transaction atomically.
     *
     * For service-only: finish without Processing, Ready, inventory, supplier
     * invoice, or physical Dispensing Event.
     *
     * For product/mixed: commit catalog inventory once and create one
     * Dispensing Event when a physical product is handed over.
     *
     * @return array{quotation: Quotation, job_order: JobOrder, billing_record: BillingRecord, dispensing_event: ?DispensingEvent}
     */
    public function handle(
        Quotation $quotation,
        ?Carbon $paymentDueDate = null,
        ?float $depositAmount = null,
        ?string $depositPaymentMethod = null,
        ?string $depositReference = null,
        ?string $recipientName = null,
    ): array {
        if (! in_array($quotation->status, [QuotationStatus::Draft, QuotationStatus::Presented, QuotationStatus::Accepted], true)) {
            throw ValidationException::withMessages([
                'quotation' => ['Only draft, presented, or accepted quotations can be confirmed.'],
            ]);
        }

        /** @var User $confirmer */
        $confirmer = auth()->user();

        return DB::transaction(function () use ($quotation, $paymentDueDate, $depositAmount, $depositPaymentMethod, $depositReference, $recipientName, $confirmer) {
            // Lock and accept the quotation if not already
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);

            if ($quotation->status !== QuotationStatus::Accepted) {
                $quotation->update([
                    'status' => QuotationStatus::Accepted,
                    'confirmed_by' => $confirmer->id,
                    'confirmed_at' => now(),
                ]);
            }

            // Create Job Order only for product items
            $jobOrder = JobOrder::where('quotation_id', $quotation->id)->first();
            $productItems = $quotation->items()
                ->where('item_type', TransactionItemType::Product)
                ->get();

            if ($jobOrder === null && $productItems->isNotEmpty()) {
                // Create in queued state for inventory commitment
                $jobOrder = JobOrder::create([
                    'patient_id' => $quotation->patient_id,
                    'encounter_id' => $quotation->encounter_id,
                    'prescription_id' => $quotation->prescription_id,
                    'quotation_id' => $quotation->id,
                    'status' => JobOrderStatus::Queued,
                    'fulfillment_mode' => 'immediate',
                    'uses_external_supplier' => false,
                    'total_amount' => $productItems->sum('amount'),
                    'eyewear_key' => $quotation->eyewear_key,
                ]);

                // Snapshot Product items only
                foreach ($productItems as $item) {
                    $jobOrder->items()->create([
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'amount' => $item->amount,
                        'product_variant_id' => $item->product_variant_id,
                        'lens_category_id' => $item->lens_category_id,
                        'item_type' => TransactionItemType::Product,
                    ]);
                }

                // Commit inventory for catalog-backed items
                app(CommitJobOrderInventory::class)->handle($jobOrder);

                // Complete immediately
                $jobOrder->update([
                    'status' => JobOrderStatus::Dispensed,
                    'started_at' => now(),
                    'dispensed_at' => now(),
                ]);
            }

            // Resolve or create Billing Record
            $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
                patient: $quotation->patient,
                jobOrder: $jobOrder,
                encounter: $quotation->encounter,
            );

            // Snapshot items into billing
            if ($jobOrder !== null) {
                app(AppendJobOrderItemsToBillingRecord::class)->handle(
                    jobOrder: $jobOrder,
                    billingRecord: $billingRecord,
                    discountAmount: (float) $quotation->discount_amount,
                );
            } else {
                // Service-only: add service items directly to billing
                $serviceItems = $quotation->items()
                    ->where('item_type', TransactionItemType::Service)
                    ->get();

                foreach ($serviceItems as $item) {
                    BillingRecordItem::create([
                        'billing_record_id' => $billingRecord->id,
                        'item_type' => TransactionItemType::Service,
                        'source_kind' => BillingItemSourceKind::Quotation,
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'amount' => $item->amount,
                        'quotation_item_id' => $item->id,
                        'encounter_id' => null,
                    ]);
                }

                // Apply discount and recalculate
                $billingRecord->update([
                    'quotation_id' => $quotation->id,
                    'discount_amount' => $quotation->discount_amount,
                ]);

                $billingRecord->refresh();
                app(RecalculateBillingRecordTotals::class)->handle(
                    $billingRecord,
                    discountAmount: (float) $quotation->discount_amount,
                );
            }

            // Set payment due date
            if ($paymentDueDate !== null) {
                $billingRecord->update(['payment_due_date' => $paymentDueDate]);
            }

            // Record optional deposit
            if ($depositAmount !== null && $depositAmount > 0) {
                app(RecordBillingPayment::class)->handle(
                    billingRecord: $billingRecord,
                    amount: $depositAmount,
                    paymentMethod: $depositPaymentMethod ?? 'cash',
                    recorder: $confirmer,
                    referenceNumber: $depositReference,
                    notes: 'Initial deposit at immediate completion',
                    chargesReviewed: true,
                );
            }

            // Create dispensing event for product orders
            $dispensingEvent = null;
            $hasProductItems = $jobOrder !== null && $jobOrder->items()->exists();

            if ($hasProductItems) {
                $dispensingEvent = DispensingEvent::create([
                    'job_order_id' => $jobOrder->id,
                    'billing_record_id' => $billingRecord->id,
                    'dispensed_by' => $confirmer->id,
                    'recipient_name' => $recipientName,
                    'notes' => 'Immediate completion',
                ]);
            }

            return [
                'quotation' => $quotation->fresh(),
                'job_order' => $jobOrder,
                'billing_record' => $billingRecord->fresh(),
                'dispensing_event' => $dispensingEvent,
            ];
        });
    }
}
