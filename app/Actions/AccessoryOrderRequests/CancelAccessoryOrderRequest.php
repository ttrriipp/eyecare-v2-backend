<?php

namespace App\Actions\AccessoryOrderRequests;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\AuditEvent;
use App\Models\AccessoryOrderRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelAccessoryOrderRequest
{
    public function __construct(private readonly CreateAuditLog $auditLog) {}

    public function handle(
        AccessoryOrderRequest $orderRequest,
        User $account,
        string $reasonDetails,
    ): AccessoryOrderRequest {
        return DB::transaction(function () use ($orderRequest, $account, $reasonDetails): AccessoryOrderRequest {
            $lockedRequest = AccessoryOrderRequest::query()
                ->lockForUpdate()
                ->findOrFail($orderRequest->id);

            if ($lockedRequest->user_id !== $account->id) {
                abort(404);
            }

            if ($lockedRequest->status === AccessoryOrderRequestStatus::Cancelled) {
                return $lockedRequest->fresh(['items']);
            }

            if (! $lockedRequest->isPending()) {
                throw ValidationException::withMessages([
                    'request' => ['Only pending requests can be cancelled.'],
                ]);
            }

            $lockedRequest->update([
                'status' => AccessoryOrderRequestStatus::Cancelled,
                'cancelled_at' => now(),
                'encrypted_cancellation_reason' => $reasonDetails,
            ]);

            $this->auditLog->handle(
                subject: $lockedRequest,
                action: AuditEvent::AccessoryOrderRequestCancelled,
                metadata: [
                    'patient_id' => $lockedRequest->patient_id,
                    'status' => AccessoryOrderRequestStatus::Cancelled->value,
                ],
                actorId: $account->id,
            );

            return $lockedRequest->fresh(['items']);
        });
    }
}
