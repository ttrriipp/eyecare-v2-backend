<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Notifications\NotifyAdminUsers;
use App\Enums\AppointmentRescheduleRequestStatus;
use App\Enums\AuditEvent;
use App\Exceptions\AppointmentRescheduleRequestStateException;
use App\Models\AppointmentRescheduleRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WithdrawAppointmentRescheduleRequest
{
    public function __construct(
        private readonly CreateAuditLog $createAuditLog,
        private readonly NotifyAdminUsers $notifyAdminUsers,
    ) {}

    public function handle(
        AppointmentRescheduleRequest $request,
        User $account,
    ): AppointmentRescheduleRequest {
        if ($request->user_id !== $account->id || $request->patient_id !== $account->patient?->id) {
            abort(404);
        }

        $request = DB::transaction(function () use ($request, $account): AppointmentRescheduleRequest {
            $request = AppointmentRescheduleRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            if ($request->user_id !== $account->id || $request->patient_id !== $account->patient?->id) {
                abort(404);
            }

            if (! $request->isPending()) {
                throw AppointmentRescheduleRequestStateException::notCancellable();
            }

            $request->update([
                'status' => AppointmentRescheduleRequestStatus::Cancelled,
                'resolved_at' => now(),
            ]);

            $this->createAuditLog->handle(
                subject: $request,
                action: AuditEvent::AppointmentRescheduleRequestWithdrawn,
                metadata: [
                    'appointment_id' => $request->appointment_id,
                    'patient_id' => $request->patient_id,
                    'request_number' => $request->request_number,
                ],
                actorId: $account->id,
            );

            return $request->fresh();
        }, attempts: 3);

        $this->notifyAdminUsers->appointmentRescheduleRequestWithdrawn($request);

        return $request;
    }
}
