<?php

namespace App\Actions\OpticalOrders;

use App\Actions\Reservations\ConvertFrameReservationToJobOrder;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\BillingRecord;
use App\Models\FrameReservation;
use App\Models\JobOrder;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptAndStartOpticalOrder
{
    public function handle(
        Quotation $quotation,
        ?float $depositAmount = null,
        ?int $frameReservationId = null,
    ): array {
        if ($quotation->status !== QuotationStatus::Presented
            && $quotation->status !== QuotationStatus::Accepted) {
            throw ValidationException::withMessages([
                'quotation' => ['Only presented or accepted quotations can be started.'],
            ]);
        }

        return DB::transaction(function () use ($quotation, $frameReservationId) {
            // Accept the quotation if not already
            if ($quotation->status === QuotationStatus::Presented) {
                $quotation->update([
                    'status' => QuotationStatus::Accepted,
                    'accepted_at' => now(),
                    'accepted_by' => auth()->id(),
                ]);
            }

            // Create or return existing Job Order
            $jobOrder = JobOrder::where('quotation_revision_id', $quotation->latestRevision?->id)->first();

            if ($jobOrder === null) {
                $jobOrder = JobOrder::create([
                    'patient_id' => $quotation->patient_id,
                    'encounter_id' => $quotation->encounter_id,
                    'prescription_id' => $quotation->prescription_id,
                    'quotation_revision_id' => $quotation->latestRevision?->id,
                    'status' => JobOrderStatus::Queued,
                    'total_amount' => $quotation->latestRevision?->total ?? 0,
                    'eyewear_key' => $quotation->eyewear_key,
                ]);
            }

            // Create or return existing Billing Record
            $billingRecord = BillingRecord::where('job_order_id', $jobOrder->id)->first();

            if ($billingRecord === null) {
                $billingRecord = BillingRecord::create([
                    'patient_id' => $quotation->patient_id,
                    'job_order_id' => $jobOrder->id,
                    'encounter_id' => $quotation->encounter_id,
                    'status' => 'unpaid',
                    'total_amount' => $jobOrder->total_amount,
                    'amount_paid' => 0,
                    'balance_due' => $jobOrder->total_amount,
                    'recorded_by' => auth()->id(),
                    'recorded_at' => now(),
                ]);
            }

            // Convert frame reservation if provided
            if ($frameReservationId !== null) {
                $reservation = FrameReservation::find($frameReservationId);
                if ($reservation !== null) {
                    app(ConvertFrameReservationToJobOrder::class)->handle($reservation, $jobOrder);
                }
            }

            return [
                'quotation' => $quotation,
                'job_order' => $jobOrder,
                'billing_record' => $billingRecord,
            ];
        });
    }
}
