<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
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

class ApproveAppointmentRescheduleRequest
{
    public function __construct(
        private readonly CommitAppointmentReschedule $commitAppointmentReschedule,
        private readonly CreateAuditLog $createAuditLog,
    ) {}

    public function handle(
        AppointmentRescheduleRequest $request,
        CarbonInterface $selectedScheduledAt,
        User $reviewer,
    ): AppointmentRescheduleRequest {
        $this->validateReviewer($reviewer);
        $selectedScheduledAt = $selectedScheduledAt->copy()->setTimezone(config('app.timezone'));

        return DB::transaction(function () use ($request, $selectedScheduledAt, $reviewer): AppointmentRescheduleRequest {
            $requestSnapshot = AppointmentRescheduleRequest::query()->findOrFail($request->getKey());
            $lockedAppointment = Appointment::query()
                ->with(['status', 'optometrist'])
                ->lockForUpdate()
                ->findOrFail($requestSnapshot->appointment_id);
            $lockedRequest = AppointmentRescheduleRequest::query()
                ->lockForUpdate()
                ->findOrFail($requestSnapshot->getKey());

            if ($lockedRequest->appointment_id !== $lockedAppointment->id) {
                throw AppointmentRescheduleRequestStateException::stale();
            }

            $lockedRequest->setRelation('appointment', $lockedAppointment);

            if ($lockedRequest->status === AppointmentRescheduleRequestStatus::Approved) {
                if ($lockedRequest->selected_scheduled_at?->equalTo($selectedScheduledAt)
                    && $lockedRequest->appointment_reschedule_id !== null) {
                    return $this->freshRequest($lockedRequest);
                }

                throw AppointmentRescheduleRequestStateException::notApprovable();
            }

            if (! $lockedRequest->isPending()) {
                throw AppointmentRescheduleRequestStateException::notApprovable();
            }

            $this->validateAppointmentSnapshot($lockedRequest, $lockedAppointment);
            $this->validateSubmittedSelection($lockedRequest, $selectedScheduledAt);

            $history = $this->commitAppointmentReschedule->handle(
                appointment: $lockedAppointment,
                scheduledAt: $selectedScheduledAt,
                initiator: 'patient',
                actor: $reviewer,
            );

            $lockedRequest->update([
                'status' => AppointmentRescheduleRequestStatus::Approved,
                'selected_scheduled_at' => $selectedScheduledAt,
                'resolved_by_user_id' => $reviewer->id,
                'resolved_at' => now(),
                'appointment_reschedule_id' => $history->id,
            ]);

            $this->createAuditLog->handle(
                subject: $lockedRequest,
                action: AuditEvent::AppointmentRescheduleRequestApproved,
                metadata: [
                    'appointment_id' => $lockedRequest->appointment_id,
                    'request_number' => $lockedRequest->request_number,
                    'selected_scheduled_at' => $selectedScheduledAt->toIso8601String(),
                    'appointment_reschedule_id' => $history->id,
                    'reviewer_id' => $reviewer->id,
                ],
                actorId: $reviewer->id,
            );

            return $this->freshRequest($lockedRequest);
        }, attempts: 3);
    }

    private function validateReviewer(User $reviewer): void
    {
        if (! $reviewer->is_active || (! $reviewer->isAdmin() && ! $reviewer->isStaff())) {
            throw ValidationException::withMessages([
                'reviewer' => ['Only active staff or administrators can approve reschedule requests.'],
            ]);
        }
    }

    private function validateAppointmentSnapshot(
        AppointmentRescheduleRequest $request,
        Appointment $appointment,
    ): void {
        if ($appointment->status?->name !== AppointmentStatusName::Scheduled->value
            || ! $appointment->scheduled_at instanceof CarbonInterface
            || ! $appointment->scheduled_at->isFuture()
            || ! $request->current_scheduled_at instanceof CarbonInterface
            || ! $appointment->scheduled_at->equalTo($request->current_scheduled_at)) {
            throw AppointmentRescheduleRequestStateException::stale();
        }
    }

    private function validateSubmittedSelection(
        AppointmentRescheduleRequest $request,
        CarbonInterface $selectedScheduledAt,
    ): void {
        try {
            $isSubmitted = collect($request->submittedScheduledTimes())
                ->contains(fn (CarbonInterface $submitted): bool => $submitted->equalTo($selectedScheduledAt));
        } catch (\Throwable) {
            throw AppointmentRescheduleRequestStateException::selectionInvalid();
        }

        if (! $isSubmitted) {
            throw AppointmentRescheduleRequestStateException::selectionInvalid();
        }
    }

    private function freshRequest(AppointmentRescheduleRequest $request): AppointmentRescheduleRequest
    {
        return $request->fresh([
            'appointment.status',
            'appointmentReschedule',
        ]);
    }
}
