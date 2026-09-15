<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\AppointmentRequest;

/**
 * Result of evaluating a patient account's booking eligibility.
 */
final class BookingEligibility
{
    public function __construct(
        public readonly bool $canSubmitNewRequest,
        public readonly ?string $blockingReason,
        public readonly ?AppointmentRequest $activeRequest,
        public readonly ?Appointment $activeAppointment,
        public readonly bool $canRequestRebooking,
    ) {}

    public static function eligible(): self
    {
        return new self(
            canSubmitNewRequest: true,
            blockingReason: null,
            activeRequest: null,
            activeAppointment: null,
            canRequestRebooking: false,
        );
    }

    public static function blockedByRequest(AppointmentRequest $request): self
    {
        return new self(
            canSubmitNewRequest: false,
            blockingReason: 'active_request_exists',
            activeRequest: $request,
            activeAppointment: null,
            canRequestRebooking: false,
        );
    }

    public static function blockedByAppointment(Appointment $appointment): self
    {
        $canRebook = $appointment->status?->name === 'scheduled';

        return new self(
            canSubmitNewRequest: false,
            blockingReason: $appointment->status?->name === 'checked_in'
                ? 'checked_in_appointment_exists'
                : 'scheduled_appointment_exists',
            activeRequest: null,
            activeAppointment: $appointment,
            canRequestRebooking: $canRebook,
        );
    }

    /**
     * @return array{can_submit_new_request: bool, blocking_reason: ?string, active_request_id: ?int, appointment_id: ?int, appointment_status: ?string, can_request_rebooking: bool}
     */
    public function toArray(): array
    {
        return [
            'can_submit_new_request' => $this->canSubmitNewRequest,
            'blocking_reason' => $this->blockingReason,
            'active_request_id' => $this->activeRequest?->id,
            'appointment_id' => $this->activeAppointment?->id,
            'appointment_status' => $this->activeAppointment?->status?->name,
            'can_request_rebooking' => $this->canRequestRebooking,
        ];
    }
}
