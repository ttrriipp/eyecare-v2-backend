<?php

namespace App\Actions\Quotations;

use App\Actions\BillingRecords\AppendJobOrderItemsToBillingRecord;
use App\Actions\BillingRecords\AppendQuotedServicesToBillingRecord;
use App\Actions\BillingRecords\RecalculateBillingRecordTotals;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Actions\Reservations\ConvertFrameReservationToJobOrder;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Models\BillingRecord;
use App\Models\FrameReservation;
use App\Models\JobOrder;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmQuotationSale
{
    /**
     * Confirm a quotation sale atomically.
     *
     * Creates Optical Order from Product lines only.
     * Copies selected performed Services into Billing.
     * Idempotent - repeated calls return existing records.
     *
     * @param  array<int, int>  $performedServiceItemIds  IDs of Quotation Items (Services) to bill
     * @return array{quotation: Quotation, optical_order: ?JobOrder, billing_record: BillingRecord}
     */
    public function handle(
        Quotation $quotation,
        User $confirmer,
        array $performedServiceItemIds = [],
        ?Carbon $paymentDueDate = null,
        ?float $depositAmount = null,
        ?string $depositPaymentMethod = null,
        ?string $depositReference = null,
        ?int $frameReservationId = null,
    ): array {
        // Validate status
        if (! in_array($quotation->status, [QuotationStatus::Draft, QuotationStatus::Presented, QuotationStatus::Accepted], true)) {
            throw ValidationException::withMessages([
                'quotation' => ['Only draft, presented, or accepted quotations can be confirmed.'],
            ]);
        }

        return DB::transaction(function () use ($quotation, $confirmer, $performedServiceItemIds, $paymentDueDate, $depositAmount, $depositPaymentMethod, $depositReference, $frameReservationId) {
            // Lock and accept the quotation if not already
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);

            if ($quotation->status !== QuotationStatus::Accepted) {
                $quotation->update([
                    'status' => QuotationStatus::Accepted,
                    'confirmed_by' => $confirmer->id,
                    'confirmed_at' => now(),
                ]);
            }

            // Separate Product and Service items
            $productItems = $quotation->items()
                ->where('item_type', TransactionItemType::Product)
                ->get();

            $serviceItems = $quotation->items()
                ->where('item_type', TransactionItemType::Service)
                ->get();

            // Validate optical build before any mutation
            $prescription = $quotation->prescription_id
                ? Prescription::find($quotation->prescription_id)
                : null;

            app(ValidateOpticalQuotation::class)->handle(
                items: $productItems->map(fn ($item) => [
                    'item_kind' => $item->item_kind,
                    'product_variant_id' => $item->product_variant_id,
                ])->values(),
                patient: $quotation->patient,
                prescription: $prescription,
            );

            // Create Optical Order only if there are Product lines
            $opticalOrder = null;
            if ($productItems->isNotEmpty()) {
                $opticalOrder = JobOrder::where('quotation_id', $quotation->id)->first();

                if ($opticalOrder === null) {
                    $opticalOrder = JobOrder::create([
                        'patient_id' => $quotation->patient_id,
                        'encounter_id' => $quotation->encounter_id,
                        'prescription_id' => $quotation->prescription_id,
                        'quotation_id' => $quotation->id,
                        'status' => JobOrderStatus::Queued,
                        'fulfillment_mode' => 'prepared',
                        'uses_external_supplier' => false,
                        'total_amount' => $productItems->sum('amount'),
                        'eyewear_key' => $quotation->eyewear_key,
                    ]);

                    // Snapshot Product items into Job Order
                    foreach ($productItems as $item) {
                        $opticalOrder->items()->create([
                            'description' => $item->description,
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'amount' => $item->amount,
                            'product_variant_id' => $item->product_variant_id,
                            'lens_category_id' => $item->lens_category_id,
                            'item_type' => TransactionItemType::Product,
                            'item_kind' => $item->item_kind,
                            'item_snapshot' => $item->item_snapshot,
                        ]);
                    }

                    // Convert the frame reservation first, if one was selected — its
                    // variants already have stock allocated, so they must be excluded
                    // from the normal commitment below to avoid double-committing.
                    $reservedVariantIds = [];

                    if ($frameReservationId !== null) {
                        $reservation = FrameReservation::find($frameReservationId);

                        if ($reservation !== null) {
                            app(ConvertFrameReservationToJobOrder::class)->handle($reservation, $opticalOrder);
                            $reservedVariantIds = $reservation->items->pluck('product_variant_id')->all();
                        }
                    }

                    // Commit inventory for catalog-backed items not already covered above
                    app(CommitJobOrderInventory::class)->handle($opticalOrder, excludeProductVariantIds: $reservedVariantIds);
                }
            }

            // Resolve or create the Billing Record
            $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
                patient: $quotation->patient,
                jobOrder: $opticalOrder,
                encounter: $quotation->encounter,
            );

            // Append Optical Order Product items to Billing
            if ($opticalOrder !== null) {
                app(AppendJobOrderItemsToBillingRecord::class)->handle(
                    jobOrder: $opticalOrder,
                    billingRecord: $billingRecord,
                );
            }

            // Append selected performed Services to Billing
            if (! empty($performedServiceItemIds)) {
                app(AppendQuotedServicesToBillingRecord::class)->handle(
                    billingRecord: $billingRecord,
                    quotationItemIds: $performedServiceItemIds,
                );
            }

            // Set quotation link and discount on billing
            $billingRecord->update([
                'quotation_id' => $quotation->id,
                'discount_amount' => $quotation->discount_amount,
                'payment_due_date' => $paymentDueDate,
            ]);

            // Recalculate totals
            $billingRecord->refresh();
            app(RecalculateBillingRecordTotals::class)->handle(
                $billingRecord,
                discountAmount: (float) $quotation->discount_amount,
            );

            // Record optional deposit
            if ($depositAmount !== null && $depositAmount > 0) {
                app(RecordBillingPayment::class)->handle(
                    billingRecord: $billingRecord,
                    amount: $depositAmount,
                    paymentMethod: $depositPaymentMethod ?? 'cash',
                    recorder: $confirmer,
                    referenceNumber: $depositReference,
                    notes: 'Initial deposit at confirmation',
                    chargesReviewed: true,
                );
            }

            return [
                'quotation' => $quotation->fresh(),
                'optical_order' => $opticalOrder,
                'billing_record' => $billingRecord->fresh(),
            ];
        });
    }
}
