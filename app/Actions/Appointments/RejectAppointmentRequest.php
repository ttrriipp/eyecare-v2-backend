<?php

namespace App\Actions\Appointments;

use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RejectAppointmentRequest
{
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

        $request->update([
            'status' => AppointmentRequestStatus::Rejected,
            'resolved_by_user_id' => $reviewer->id,
            'resolved_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $request->fresh();
    }
}
