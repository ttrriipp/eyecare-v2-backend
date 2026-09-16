<?php

namespace App\Actions\Appointments;

use App\Enums\AppointmentRequestKind;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\User;

/**
 * Resolve the authenticated account's single current appointment journey.
 */
class ResolveCurrentBooking
{
    public function __construct(
        private EvaluateBookingEligibility $evaluateBookingEligibility,
    ) {}

    /**
     * @return array{kind: 'none'}|array{kind: 'pending_request', request: AppointmentRequest}|array{kind: 'appointment', appointment: Appointment, original_request: ?AppointmentRequest, pending_reschedule: ?AppointmentRequest}
     */
    public function handle(User $account): array
    {
        $eligibility = $this->evaluateBookingEligibility->handle($account);

        if ($eligibility->activeRequest !== null) {
            $request = $this->loadRequest($eligibility->activeRequest);

            if (! $request->isRebooking()) {
                return [
                    'kind' => 'pending_request',
                    'request' => $request,
                ];
            }

            if ($request->appointment !== null) {
                $appointment = $this->loadAppointment($request->appointment);

                if ($this->isCurrentAppointment($appointment)) {
                    return $this->appointmentState(
                        account: $account,
                        appointment: $appointment,
                        pendingReschedule: $request,
                    );
                }
            }
        }

        if ($eligibility->activeAppointment === null) {
            return ['kind' => 'none'];
        }

        $appointment = $this->loadAppointment($eligibility->activeAppointment);

        return $this->appointmentState(
            account: $account,
            appointment: $appointment,
            pendingReschedule: null,
        );
    }

    private function loadRequest(AppointmentRequest $request): AppointmentRequest
    {
        return $request->load([
            'appointmentType',
            'appointment.appointmentType',
            'appointment.status',
            'appointment.optometrist',
            'appointment.latestReschedule',
            'appointment.visitRating',
        ]);
    }

    private function loadAppointment(Appointment $appointment): Appointment
    {
        return $appointment->load([
            'appointmentType',
            'status',
            'optometrist',
            'latestReschedule',
            'visitRating',
        ]);
    }

    private function isCurrentAppointment(Appointment $appointment): bool
    {
        if ($appointment->status?->name === AppointmentStatusName::CheckedIn->value) {
            return true;
        }

        return $appointment->status?->name === AppointmentStatusName::Scheduled->value
            && $appointment->scheduled_at->isFuture();
    }

    /**
     * @return array{kind: 'appointment', appointment: Appointment, original_request: ?AppointmentRequest, pending_reschedule: ?AppointmentRequest}
     */
    private function appointmentState(
        User $account,
        Appointment $appointment,
        ?AppointmentRequest $pendingReschedule,
    ): array {
        return [
            'kind' => 'appointment',
            'appointment' => $appointment,
            'original_request' => $this->findOriginalRequest($account, $appointment),
            'pending_reschedule' => $pendingReschedule,
        ];
    }

    private function findOriginalRequest(User $account, Appointment $appointment): ?AppointmentRequest
    {
        return AppointmentRequest::query()
            ->where('user_id', $account->id)
            ->where('appointment_id', $appointment->id)
            ->where('request_type', AppointmentRequestKind::New->value)
            ->where('status', AppointmentRequestStatus::Accepted->value)
            ->with(['appointmentType', 'appointment.status'])
            ->oldest('created_at')
            ->oldest('id')
            ->first();
    }
}
