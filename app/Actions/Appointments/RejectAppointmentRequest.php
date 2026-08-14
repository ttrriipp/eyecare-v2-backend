<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AuditEvent;
use App\Models\AppointmentRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectAppointmentRequest
{
    public function __construct(private readonly CreateAuditLog $createAuditLog) {}

    public function handle(
        AppointmentRequest $request,
        User $reviewer,
        ?string $reason = null,
    ): AppointmentRequest {
        if ($request->status !== AppointmentRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'request' => ['Only pending appointment requests can be rejected.'],
            ]);
        }

        return DB::transaction(function () use ($request, $reviewer, $reason): AppointmentRequest {
            $request->update([
                'status' => AppointmentRequestStatus::Rejected,
                'resolved_by_user_id' => $reviewer->id,
                'resolved_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->createAuditLog->handle(
                subject: $request,
                action: AuditEvent::AppointmentRequestRejected,
                metadata: [
                    'patient_id' => $request->patient_id,
                    'reason_provided' => filled($reason),
                ],
                actorId: $reviewer->id,
            );

            return $request->fresh();
        });
    }
}
