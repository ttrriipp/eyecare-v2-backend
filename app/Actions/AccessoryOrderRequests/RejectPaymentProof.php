<?php

namespace App\Actions\AccessoryOrderRequests;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\JobOrders\CancelOpticalOrder;
use App\Enums\AuditEvent;
use App\Enums\JobOrderStatus;
use App\Enums\OrderPaymentProofStatus;
use App\Models\OrderPaymentProof;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectPaymentProof
{
    public function __construct(
        private readonly CancelOpticalOrder $cancelOrder,
        private readonly CreateAuditLog $auditLog,
    ) {}

    public function handle(
        OrderPaymentProof $proof,
        User $reviewer,
        string $reason,
    ): OrderPaymentProof {
        return DB::transaction(function () use ($proof, $reviewer, $reason): OrderPaymentProof {
            $lockedProof = OrderPaymentProof::query()->lockForUpdate()->findOrFail($proof->id);

            if ($lockedProof->status !== OrderPaymentProofStatus::Pending) {
                throw ValidationException::withMessages([
                    'proof' => ['Only pending proofs can be rejected.'],
                ]);
            }

            $order = $lockedProof->jobOrder;

            if ($order === null || $order->status !== JobOrderStatus::PaymentReview) {
                throw ValidationException::withMessages([
                    'order' => ['This order is not in payment review.'],
                ]);
            }

            // Mark proof rejected
            $lockedProof->update([
                'status' => OrderPaymentProofStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            // Cancel the order (reverses inventory, voids billing)
            $this->cancelOrder->handle(
                order: $order,
                reason: 'Payment proof rejected: '.$reason,
                actor: $reviewer,
            );

            $this->auditLog->handle(
                subject: $lockedProof,
                action: AuditEvent::PaymentProofRejected,
                metadata: [
                    'job_order_id' => $order->id,
                    'reason' => $reason,
                ],
                actorId: $reviewer->id,
            );

            return $lockedProof->fresh();
        });
    }
}
