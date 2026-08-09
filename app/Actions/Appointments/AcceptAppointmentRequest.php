<?php

namespace App\Actions\Appointments;

use App\Enums\AppointmentRequestStatus;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptAppointmentRequest
{
    public function __construct(
        private readonly EvaluateAppointmentAvailability $evaluateAvailability,
        private readonly LockAppointmentScheduleDate $lockScheduleDate,
    ) {}

    /**
     * Accept an appointment request, creating a confirmed appointment.
     *
     * Requires final provider, type, duration, and start. Uses schedule-date
     * lock and retries on deadlock.
     */
    public function handle(
        AppointmentRequest $request,
        User $reviewer,
        AppointmentType $appointmentType,
        int $durationMinutes,
        CarbonInterface $scheduledAt,
        User $optometrist,
        ?string $referringSource = null,
        ?string $contactNote = null,
    ): Appointment {
        // Idempotent: return existing appointment if already accepted
        if ($request->status === AppointmentRequestStatus::Accepted && $request->appointment_id !== null) {
            return $request->appointment;
        }

        if ($request->status !== AppointmentRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'request' => ['Only pending appointment requests can be accepted.'],
            ]);
        }

        if ($request->patient_id === null) {
            throw ValidationException::withMessages([
                'request' => ['Patient must be resolved before accepting the request.'],
            ]);
        }

        // Validate the optometrist is active
        if (! $optometrist->isOptometrist() || ! $optometrist->is_active) {
            throw ValidationException::withMessages([
                'optometrist_id' => ['The selected optometrist is not available.'],
            ]);
        }

        // Validate referral source if required
        if ($appointmentType->requires_referral && empty($referringSource)) {
            throw ValidationException::withMessages([
                'referring_source' => ['Referring source is required for this appointment type.'],
            ]);
        }

        $maxRetries = 3;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                return $this->attemptAcceptance(
                    request: $request,
                    reviewer: $reviewer,
                    appointmentType: $appointmentType,
                    durationMinutes: $durationMinutes,
                    scheduledAt: $scheduledAt,
                    optometrist: $optometrist,
                    referringSource: $referringSource,
                    contactNote: $contactNote,
                );
            } catch (QueryException $e) {
                if ($attempt === $maxRetries - 1 || ! str_contains($e->getMessage(), 'Deadlock')) {
                    throw $e;
                }

                usleep(100 * ($attempt + 1)); // Exponential backoff
            }
        }

        throw ValidationException::withMessages([
            'request' => ['Unable to accept request due to concurrent modification. Please try again.'],
        ]);
    }

    private function attemptAcceptance(
        AppointmentRequest $request,
        User $reviewer,
        AppointmentType $appointmentType,
        int $durationMinutes,
        CarbonInterface $scheduledAt,
        User $optometrist,
        ?string $referringSource,
        ?string $contactNote,
    ): Appointment {
        return DB::transaction(function () use (
            $request,
            $reviewer,
            $appointmentType,
            $durationMinutes,
            $scheduledAt,
            $optometrist,
            $referringSource,
            $contactNote,
        ) {
            // Lock the schedule date
            $this->lockScheduleDate->handle($scheduledAt);

            // Lock the request row
            $request = AppointmentRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status !== AppointmentRequestStatus::Pending) {
                // Already processed (idempotent)
                if ($request->appointment_id !== null) {
                    return $request->appointment;
                }
                throw ValidationException::withMessages([
                    'request' => ['This request has already been processed.'],
                ]);
            }

            // Recheck the provider interval under the lock
            if (! $this->evaluateAvailability->isOptometristEligible($optometrist, $scheduledAt, $scheduledAt->copy()->addMinutes($durationMinutes))) {
                throw ValidationException::withMessages([
                    'optometrist_id' => ['The selected optometrist is no longer available for this time slot.'],
                ]);
            }

            // Recheck general availability
            $decision = $this->evaluateAvailability->handle(
                startsAt: $scheduledAt,
                durationMinutes: $durationMinutes,
                optometrist: $optometrist,
            );

            if (! $decision->available) {
                throw ValidationException::withMessages([
                    'scheduled_at' => ["This time slot is no longer available ({$decision->reason})."],
                ]);
            }

            // Create the appointment
            $scheduledStatus = AppointmentStatus::where('name', 'scheduled')->firstOrFail();

            $appointment = Appointment::create([
                'patient_id' => $request->patient_id,
                'appointment_type_id' => $appointmentType->id,
                'appointment_status_id' => $scheduledStatus->id,
                'optometrist_id' => $optometrist->id,
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $durationMinutes,
                'source' => 'mobile',
                'reason_for_visit' => $request->encrypted_reason_for_visit,
                'referring_source' => $referringSource,
                'contact_notes' => $contactNote,
                'staff_notes' => null,
            ]);

            // Update the request
            $request->update([
                'status' => AppointmentRequestStatus::Accepted,
                'appointment_id' => $appointment->id,
                'appointment_type_id' => $appointmentType->id,
                'resolved_by_user_id' => $reviewer->id,
                'resolved_at' => now(),
            ]);

            return $appointment->load(['appointmentType', 'status', 'patient', 'optometrist']);
        });
    }
}
