<?php

namespace App\Actions\OpticalOrders;

use App\Actions\BillingRecords\AppendJobOrderItemsToBillingRecord;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Actions\Quotations\ValidateQuotationFrameReservation;
use App\Actions\Reservations\ConvertFrameReservationToJobOrder;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptAndStartOpticalOrder
{
    /**
     * Confirm a quotation and create Job Order + Billing Record atomically.
     *
     * Accepts Draft or Presented quotations. Creates exactly one Job Order
     * with a snapshot of all direct Quotation items. Idempotent — repeated
     * calls return existing records without duplication.
     *
     * @return array{quotation: Quotation, job_order: JobOrder, billing_record: BillingRecord}
     */
    public function handle(
        Quotation $quotation,
        ?Carbon $paymentDueDate = null,
        ?float $depositAmount = null,
        ?string $depositPaymentMethod = null,
        ?string $depositReference = null,
        ?int $frameReservationId = null,
        string $fulfillmentMode = 'prepared',
        bool $usesExternalSupplier = false,
        ?int $frameReservationItemId = null,
    ): array {
        if (! in_array($quotation->status, [QuotationStatus::Draft, QuotationStatus::Presented, QuotationStatus::Accepted], true)) {
            throw ValidationException::withMessages([
                'quotation' => ['Only draft, presented, or accepted quotations can be confirmed.'],
            ]);
        }

        /** @var User $confirmer */
        $confirmer = auth()->user();

        return DB::transaction(function () use ($quotation, $paymentDueDate, $depositAmount, $depositPaymentMethod, $depositReference, $frameReservationId, $fulfillmentMode, $usesExternalSupplier, $frameReservationItemId, $confirmer): array {
            // Lock before validating the reservation or mutating acceptance.
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            $wasAlreadyAccepted = $quotation->status === QuotationStatus::Accepted;
            $quotation->load('items');

            // Create or return existing Job Order (idempotent via direct quotation_id)
            $jobOrder = JobOrder::query()
                ->where('quotation_id', $quotation->id)
                ->lockForUpdate()
                ->first();

            $legacyReservationId = $frameReservationId;

            if ($legacyReservationId === null
                && $frameReservationItemId === null
                && $jobOrder?->frame_reservation_id !== null) {
                $legacyReservationId = $jobOrder->frame_reservation_id;
            }

            $productItems = $quotation->items
                ->where('item_type', TransactionItemType::Product)
                ->values();

            $reservation = app(ValidateQuotationFrameReservation::class)->handle(
                quotation: $quotation,
                productItems: $productItems,
                legacyReservationItemId: $frameReservationItemId,
                legacyReservationId: $legacyReservationId,
                existingOpticalOrder: $jobOrder,
            );

            if ($reservation !== null && $quotation->frame_reservation_id === null) {
                $quotation->update(['frame_reservation_id' => $reservation->id]);
            }

            if (! $wasAlreadyAccepted) {
                $quotation->update([
                    'status' => QuotationStatus::Accepted,
                    'confirmed_by' => $confirmer->id,
                    'confirmed_at' => now(),
                ]);
            }

            if ($jobOrder === null) {
                $jobOrder = JobOrder::create([
                    'patient_id' => $quotation->patient_id,
                    'encounter_id' => $quotation->encounter_id,
                    'prescription_id' => $quotation->prescription_id,
                    'quotation_id' => $quotation->id,
                    'status' => JobOrderStatus::Queued,
                    'fulfillment_mode' => $fulfillmentMode,
                    'uses_external_supplier' => $usesExternalSupplier,
                    'total_amount' => $quotation->total,
                    'eyewear_key' => $quotation->eyewear_key,
                ]);

                // Snapshot all direct quotation items into job order items
                foreach ($quotation->items as $item) {
                    $jobOrder->items()->create([
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'amount' => $item->amount,
                        'product_variant_id' => $item->product_variant_id,
                        'lens_category_id' => $item->lens_category_id,
                        'item_type' => $item->item_type,
                    ]);
                }

                if ($reservation !== null) {
                    app(ConvertFrameReservationToJobOrder::class)->handle($reservation, $jobOrder);
                }

                // Commit every catalog-backed order item exactly once.
                app(CommitJobOrderInventory::class)->handle($jobOrder);
            }

            $jobOrder->refresh();

            $billingRecord = $jobOrder->billingRecord()->first();

            $billingRecord ??= app(ResolveOpenCheckoutBillingRecord::class)->handle(
                patient: $quotation->patient,
                jobOrder: $jobOrder,
                encounter: $quotation->encounter,
            );

            $billingRecord->update(['quotation_id' => $quotation->id]);

            // Snapshot Job Order items into Billing Record
            app(AppendJobOrderItemsToBillingRecord::class)->handle(
                jobOrder: $jobOrder,
                billingRecord: $billingRecord,
                discountAmount: (float) $quotation->discount_amount,
            );

            // Reload to get updated totals
            $billingRecord = $billingRecord->fresh();

            // Set payment due date if provided
            if ($paymentDueDate !== null) {
                $billingRecord->update(['payment_due_date' => $paymentDueDate]);
            }

            // Record optional initial deposit
            $alreadyRecordedInitialDeposit = $billingRecord->payments()
                ->where('status', 'posted')
                ->where('notes', 'Initial deposit at confirmation')
                ->exists();

            if ($depositAmount !== null && $depositAmount > 0 && ! $alreadyRecordedInitialDeposit) {
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
                'job_order' => $jobOrder,
                'billing_record' => $billingRecord->fresh(),
            ];
        });
    }
}
