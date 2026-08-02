<?php

namespace App\Actions\OpticalOrders;

use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Actions\Reservations\ConvertFrameReservationToJobOrder;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\BillingRecord;
use App\Models\FrameReservation;
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
    ): array {
        if (! in_array($quotation->status, [QuotationStatus::Draft, QuotationStatus::Presented, QuotationStatus::Accepted], true)) {
            throw ValidationException::withMessages([
                'quotation' => ['Only draft, presented, or accepted quotations can be confirmed.'],
            ]);
        }

        /** @var User $confirmer */
        $confirmer = auth()->user();

        return DB::transaction(function () use ($quotation, $paymentDueDate, $depositAmount, $depositPaymentMethod, $depositReference, $frameReservationId, $confirmer) {
            // Lock and accept the quotation if not already
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);

            if ($quotation->status !== QuotationStatus::Accepted) {
                $quotation->update([
                    'status' => QuotationStatus::Accepted,
                    'confirmed_by' => $confirmer->id,
                    'confirmed_at' => now(),
                ]);
            }

            // Create or return existing Job Order (idempotent via direct quotation_id)
            $jobOrder = JobOrder::where('quotation_id', $quotation->id)->first();

            if ($jobOrder === null) {
                $jobOrder = JobOrder::create([
                    'patient_id' => $quotation->patient_id,
                    'encounter_id' => $quotation->encounter_id,
                    'prescription_id' => $quotation->prescription_id,
                    'quotation_id' => $quotation->id,
                    'status' => JobOrderStatus::Queued,
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
                    ]);
                }

                // Commit inventory for catalog-backed items
                app(CommitJobOrderInventory::class)->handle($jobOrder);
            }

            // Create or return existing Billing Record
            $billingRecord = BillingRecord::where('job_order_id', $jobOrder->id)->first();

            if ($billingRecord === null) {
                $balance = (float) $jobOrder->total_amount;

                $billingRecord = BillingRecord::create([
                    'patient_id' => $quotation->patient_id,
                    'job_order_id' => $jobOrder->id,
                    'encounter_id' => $quotation->encounter_id,
                    'status' => 'unpaid',
                    'total_amount' => $balance,
                    'amount_paid' => 0,
                    'balance_due' => $balance,
                    'payment_due_date' => $paymentDueDate,
                    'recorded_by' => $confirmer->id,
                    'recorded_at' => now(),
                ]);

                // Record optional initial deposit
                if ($depositAmount !== null && $depositAmount > 0) {
                    app(RecordBillingPayment::class)->handle(
                        billingRecord: $billingRecord,
                        amount: $depositAmount,
                        paymentMethod: $depositPaymentMethod ?? 'cash',
                        recorder: $confirmer,
                        referenceNumber: $depositReference,
                        notes: 'Initial deposit at confirmation',
                    );
                }
            }

            // Convert frame reservation if provided
            if ($frameReservationId !== null) {
                $reservation = FrameReservation::find($frameReservationId);
                if ($reservation !== null) {
                    app(ConvertFrameReservationToJobOrder::class)->handle($reservation, $jobOrder);
                }
            }

            return [
                'quotation' => $quotation->fresh(),
                'job_order' => $jobOrder,
                'billing_record' => $billingRecord->fresh(),
            ];
        });
    }
}
