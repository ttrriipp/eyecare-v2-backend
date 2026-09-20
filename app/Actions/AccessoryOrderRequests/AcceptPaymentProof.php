<?php

namespace App\Actions\AccessoryOrderRequests;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Enums\AuditEvent;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Enums\OrderPaymentProofStatus;
use App\Models\JobOrder;
use App\Models\OrderPaymentProof;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptPaymentProof
{
    public function __construct(
        private readonly CreateAuditLog $auditLog,
    ) {}

    public function handle(
        OrderPaymentProof $proof,
        User $reviewer,
    ): OrderPaymentProof {
        return DB::transaction(function () use ($proof, $reviewer): OrderPaymentProof {
            $lockedProof = OrderPaymentProof::query()->lockForUpdate()->findOrFail($proof->id);

            if ($lockedProof->status !== OrderPaymentProofStatus::Pending) {
                throw ValidationException::withMessages([
                    'proof' => ['Only pending proofs can be accepted.'],
                ]);
            }

            $order = JobOrder::query()->lockForUpdate()->findOrFail($lockedProof->job_order_id);

            if ($order->status !== JobOrderStatus::PaymentReview) {
                throw ValidationException::withMessages([
                    'order' => ['This order is not in payment review.'],
                ]);
            }

            $billingRecord = $order->billingRecord;

            if ($billingRecord === null || $billingRecord->status === BillingRecordStatus::Voided) {
                throw ValidationException::withMessages([
                    'order' => ['No active billing record found.'],
                ]);
            }

            if ((float) $billingRecord->balance_due <= 0) {
                throw ValidationException::withMessages([
                    'order' => ['This order has no outstanding balance.'],
                ]);
            }

            // Check for duplicate GCash reference
            $duplicateRef = OrderPaymentProof::query()
                ->where('reference_number', $lockedProof->reference_number)
                ->where('status', OrderPaymentProofStatus::Accepted)
                ->where('id', '!=', $lockedProof->id)
                ->exists();

            if ($duplicateRef) {
                throw ValidationException::withMessages([
                    'reference_number' => ['This GCash reference has already been used.'],
                ]);
            }

            // Record payment
            app(RecordBillingPayment::class)->handle(
                billingRecord: $billingRecord,
                amount: (float) $billingRecord->balance_due,
                paymentMethod: 'gcash',
                recorder: $reviewer,
                referenceNumber: $lockedProof->reference_number,
                notes: "GCash payment from {$lockedProof->sender_name}",
                chargesReviewed: true,
                notifyPatient: false,
            );

            // Mark proof accepted
            $lockedProof->update([
                'status' => OrderPaymentProofStatus::Accepted,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            // Transition order to queued
            $order->update([
                'status' => JobOrderStatus::Queued,
                'payment_expires_at' => null,
            ]);

            $this->auditLog->handle(
                subject: $lockedProof,
                action: AuditEvent::PaymentProofAccepted,
                metadata: [
                    'job_order_id' => $order->id,
                    'reference_number' => $lockedProof->reference_number,
                ],
                actorId: $reviewer->id,
            );

            return $lockedProof->fresh();
        });
    }
}
