<?php

namespace App\Actions\AccessoryOrderRequests;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\AuditEvent;
use App\Enums\DiscountProofStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestDiscountProof;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptDiscountProof
{
    public function __construct(
        private readonly CreateAuditLog $auditLog,
    ) {}

    public function handle(
        AccessoryOrderRequestDiscountProof $proof,
        User $reviewer,
    ): AccessoryOrderRequestDiscountProof {
        $this->assertReviewer($reviewer);

        return DB::transaction(function () use ($proof, $reviewer): AccessoryOrderRequestDiscountProof {
            $lockedRequest = AccessoryOrderRequest::query()
                ->lockForUpdate()
                ->findOrFail($proof->accessory_order_request_id);

            if ($lockedRequest->status !== AccessoryOrderRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'order' => ['Only pending order requests can have a discount proof reviewed.'],
                ]);
            }

            if ($lockedRequest->requested_discount_type === 'none') {
                throw ValidationException::withMessages([
                    'proof' => ['This order request does not include a discount request.'],
                ]);
            }

            $lockedProof = AccessoryOrderRequestDiscountProof::query()
                ->whereKey($proof->id)
                ->where('accessory_order_request_id', $lockedRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedProof->status !== DiscountProofStatus::Pending) {
                throw ValidationException::withMessages([
                    'proof' => ['Only pending discount proofs can be accepted.'],
                ]);
            }

            $lockedProof->update([
                'status' => DiscountProofStatus::Accepted,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            $this->auditLog->handle(
                subject: $lockedProof,
                action: AuditEvent::DiscountProofAccepted,
                metadata: [
                    'accessory_order_request_id' => $lockedRequest->id,
                    'status' => DiscountProofStatus::Accepted->value,
                ],
                actorId: $reviewer->id,
            );

            return $lockedProof->fresh(['accessoryOrderRequest', 'reviewedBy']);
        });
    }

    private function assertReviewer(User $reviewer): void
    {
        if (! $reviewer->is_active || (! $reviewer->isAdmin() && ! $reviewer->isStaff())) {
            throw ValidationException::withMessages([
                'reviewer' => ['Only active staff or administrators can accept discount proofs.'],
            ]);
        }
    }
}
