<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Notifications\NotifyPatientAppointmentRescheduleRequest;
use App\Enums\AppointmentRescheduleRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Enums\AuditEvent;
use App\Exceptions\AppointmentRescheduleRequestStateException;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectAppointmentRescheduleRequest
{
    public function __construct(
        private readonly CreateAuditLog $createAuditLog,
        private readonly NotifyPatientAppointmentRescheduleRequest $notifyPatient,
    ) {}

    public function handle(
        AppointmentRescheduleRequest $request,
        ?string $rejectionReason,
        User $reviewer,
    ): AppointmentRescheduleRequest {
        $this->validateReviewer($reviewer);
        $rejectionReason = filled($rejectionReason) ? trim($rejectionReason) : '';

        if ($rejectionReason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => ['A rejection reason is required.'],
            ]);
        }

        if (mb_strlen($rejectionReason) > 1000) {
            throw ValidationException::withMessages([
                'rejection_reason' => ['The rejection reason may not exceed 1000 characters.'],
            ]);
        }

        $rejectedRequest = DB::transaction(function () use ($request, $rejectionReason, $reviewer): AppointmentRescheduleRequest {
            $requestSnapshot = AppointmentRescheduleRequest::query()->findOrFail($request->getKey());
            $lockedAppointment = Appointment::query()
                ->with('status')
                ->lockForUpdate()
                ->findOrFail($requestSnapshot->appointment_id);
            $lockedRequest = AppointmentRescheduleRequest::query()
                ->lockForUpdate()
                ->findOrFail($requestSnapshot->getKey());

            if ($lockedRequest->appointment_id !== $lockedAppointment->id) {
                throw AppointmentRescheduleRequestStateException::notRejectable();
            }

            $lockedRequest->setRelation('appointment', $lockedAppointment);

            if (! $this->isEffectivePending($lockedRequest, $lockedAppointment)) {
                throw AppointmentRescheduleRequestStateException::notRejectable();
            }

            $lockedRequest->update([
                'status' => AppointmentRescheduleRequestStatus::Rejected,
                'resolved_by_user_id' => $reviewer->id,
                'resolved_at' => now(),
                'rejection_reason' => $rejectionReason,
            ]);

            $this->createAuditLog->handle(
                subject: $lockedRequest,
                action: AuditEvent::AppointmentRescheduleRequestRejected,
                metadata: [
                    'appointment_id' => $lockedRequest->appointment_id,
                    'request_number' => $lockedRequest->request_number,
                    'reviewer_id' => $reviewer->id,
                ],
                actorId: $reviewer->id,
            );

            return $lockedRequest->fresh([
                'appointment.status',
            ]);
        }, attempts: 3);

        $this->notifyPatient->rejected($rejectedRequest);

        return $rejectedRequest;
    }

    private function validateReviewer(User $reviewer): void
    {
        if (! $reviewer->is_active || (! $reviewer->isAdmin() && ! $reviewer->isStaff())) {
            throw ValidationException::withMessages([
                'reviewer' => ['Only active staff or administrators can reject reschedule requests.'],
            ]);
        }
    }

    private function isEffectivePending(
        AppointmentRescheduleRequest $request,
        Appointment $appointment,
    ): bool {
        return $request->status === AppointmentRescheduleRequestStatus::Pending
            && $appointment->status?->name === AppointmentStatusName::Scheduled->value
            && $appointment->scheduled_at instanceof CarbonInterface
            && $appointment->scheduled_at->isFuture()
            && $request->current_scheduled_at instanceof CarbonInterface
            && $appointment->scheduled_at->equalTo($request->current_scheduled_at)
            && $request->isPending();
    }
}
