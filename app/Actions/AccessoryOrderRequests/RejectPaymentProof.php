<?php

namespace App\Actions\AccessoryOrderRequests;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\OpticalOrders\CancelOpticalOrder;
use App\Enums\AuditEvent;
use App\Enums\JobOrderStatus;
use App\Enums\OrderPaymentProofStatus;
use App\Models\JobOrder;
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
        $this->assertReviewer($reviewer);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => ['A rejection reason between 1 and 1,000 characters is required.'],
            ]);
        }

        return DB::transaction(function () use ($proof, $reviewer, $reason): OrderPaymentProof {
            $lockedProof = OrderPaymentProof::query()->lockForUpdate()->findOrFail($proof->id);

            if ($lockedProof->status !== OrderPaymentProofStatus::Pending) {
                throw ValidationException::withMessages([
                    'proof' => ['Only pending proofs can be rejected.'],
                ]);
            }

            $order = JobOrder::query()->lockForUpdate()->findOrFail($lockedProof->job_order_id);

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
                jobOrder: $order,
                reason: 'Payment proof rejected: '.$reason,
                actor: $reviewer,
            );

            $this->auditLog->handle(
                subject: $lockedProof,
                action: AuditEvent::PaymentProofRejected,
                metadata: [
                    'job_order_id' => $order->id,
                    'status' => OrderPaymentProofStatus::Rejected->value,
                ],
                actorId: $reviewer->id,
            );

            return $lockedProof->fresh();
        });
    }

    private function assertReviewer(User $reviewer): void
    {
        if (! $reviewer->is_active || (! $reviewer->isAdmin() && ! $reviewer->isStaff())) {
            throw ValidationException::withMessages([
                'reviewer' => ['Only active staff or administrators can reject payment proofs.'],
            ]);
        }
    }
}
