<?php

namespace App\Actions\AccessoryOrderRequests;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Notifications\NotifyPatientAccount;
use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\AuditEvent;
use App\Models\AccessoryOrderRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectAccessoryOrderRequest
{
    public function __construct(
        private readonly CreateAuditLog $auditLog,
        private readonly NotifyPatientAccount $notifyPatientAccount,
    ) {}

    public function handle(
        AccessoryOrderRequest $orderRequest,
        User $reviewer,
        string $reason,
    ): AccessoryOrderRequest {
        $this->assertReviewer($reviewer);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => ['A rejection reason between 1 and 1,000 characters is required.'],
            ]);
        }

        return DB::transaction(function () use ($orderRequest, $reviewer, $reason): AccessoryOrderRequest {
            $lockedRequest = AccessoryOrderRequest::query()
                ->lockForUpdate()
                ->findOrFail($orderRequest->id);

            if (! $lockedRequest->isPending()) {
                throw ValidationException::withMessages([
                    'request' => ['Only pending requests can be rejected.'],
                ]);
            }

            $lockedRequest->update([
                'status' => AccessoryOrderRequestStatus::Rejected,
                'resolved_by' => $reviewer->id,
                'resolved_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->auditLog->handle(
                subject: $lockedRequest,
                action: AuditEvent::AccessoryOrderRequestRejected,
                metadata: [
                    'patient_id' => $lockedRequest->patient_id,
                    'status' => AccessoryOrderRequestStatus::Rejected->value,
                ],
                actorId: $reviewer->id,
            );

            $lockedRequest = $lockedRequest->fresh(['patient']);
            $this->notifyPatientAccount->accessoryOrderRequestDeclined($lockedRequest);

            return $lockedRequest;
        });
    }

    private function assertReviewer(User $reviewer): void
    {
        if (! $reviewer->is_active || (! $reviewer->isAdmin() && ! $reviewer->isStaff())) {
            throw ValidationException::withMessages([
                'reviewer' => ['Only active staff or administrators can reject requests.'],
            ]);
        }
    }
}
