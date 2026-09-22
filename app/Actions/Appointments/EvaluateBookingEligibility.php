<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\User;

/**
 * Evaluate a patient account's booking eligibility.
 *
 * A patient may have at most one active booking journey:
 * - one actionable pending appointment request, or
 * - one future scheduled or currently checked-in appointment.
 *
 * A pending rebooking request for the patient's own scheduled appointment
 * is part of the same journey and is allowed.
 */
class EvaluateBookingEligibility
{
    public function handle(User $account): BookingEligibility
    {
        // Check for an actionable pending request first.
        $activeRequest = $this->findActionablePendingRequest($account);

        if ($activeRequest !== null) {
            return BookingEligibility::blockedByRequest($activeRequest);
        }

        // Check for an active appointment.
        $activeAppointment = $this->findActiveAppointment($account);

        if ($activeAppointment !== null) {
            return BookingEligibility::blockedByAppointment($activeAppointment);
        }

        return BookingEligibility::eligible();
    }

    /**
     * Find an actionable pending request owned by this account.
     *
     * Excludes rebooking requests whose linked appointment is no longer scheduled.
     */
    private function findActionablePendingRequest(User $account): ?AppointmentRequest
    {
        return AppointmentRequest::query()
            ->where('user_id', $account->id)
            ->actionablePending()
            ->first();
    }

    /**
     * Find an active appointment owned by this account.
     *
     * Active means:
     * - future scheduled, or
     * - currently checked-in.
     *
     * Past scheduled, fulfilled, cancelled, and no-show are not active.
     */
    private function findActiveAppointment(User $account): ?Appointment
    {
        $patientId = $account->patient?->id;

        if ($patientId === null) {
            return null;
        }

        return Appointment::query()
            ->activeForPatient($patientId)
            ->first();
    }
}
