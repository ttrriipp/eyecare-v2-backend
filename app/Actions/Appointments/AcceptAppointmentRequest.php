<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AuditEvent;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptAppointmentRequest
{
    public function __construct(
        private readonly EvaluateAppointmentAvailability $evaluateAvailability,
        private readonly LockAppointmentScheduleDate $lockScheduleDate,
        private readonly CreateAuditLog $createAuditLog,
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
        ?User $optometrist = null,
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

        if (! $appointmentType->is_active) {
            throw ValidationException::withMessages([
                'appointment_type_id' => ['The selected appointment type is inactive.'],
            ]);
        }

        if ($durationMinutes < 5 || $durationMinutes > 240 || $durationMinutes % 5 !== 0) {
            throw ValidationException::withMessages([
                'duration_minutes' => ['Duration must be between 5 and 240 minutes in 5-minute increments.'],
            ]);
        }

        // Validate the optometrist is active (if provided)
        if ($optometrist !== null && (! $optometrist->isOptometrist() || ! $optometrist->is_active)) {
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

        if (! $this->matchesSubmittedPreference($request, $scheduledAt) && blank($contactNote)) {
            throw ValidationException::withMessages([
                'contact_note' => ['A contact note is required when the final time differs from submitted preferences.'],
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
        ?User $optometrist,
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

            // Recheck the provider interval under the lock (only if optometrist assigned)
            if ($optometrist !== null && ! $this->evaluateAvailability->isOptometristEligible($optometrist, $scheduledAt, $scheduledAt->copy()->addMinutes($durationMinutes))) {
                throw ValidationException::withMessages([
                    'optometrist_id' => ['The selected optometrist is no longer available for this time slot.'],
                ]);
            }

            // Recheck general availability
            $decision = $this->evaluateAvailability->handle(
                startsAt: $scheduledAt,
                durationMinutes: $durationMinutes,
                optometrist: $optometrist,
                enforceGrid: true,
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
                'optometrist_id' => $optometrist?->id,
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

            $this->createAuditLog->handle(
                subject: $request,
                action: AuditEvent::AppointmentRequestAccepted,
                metadata: [
                    'appointment_id' => $appointment->id,
                    'appointment_type_id' => $appointmentType->id,
                    'patient_id' => $request->patient_id,
                    'optometrist_id' => $optometrist?->id,
                ],
                actorId: $reviewer->id,
            );

            $this->createSmsNotification($appointment);

            return $appointment->load(['appointmentType', 'status', 'patient', 'optometrist']);
        });
    }

    private function matchesSubmittedPreference(AppointmentRequest $request, CarbonInterface $scheduledAt): bool
    {
        return collect($request->getAllTimePreferences())
            ->contains(fn (string $preference): bool => Carbon::parse($preference)->equalTo($scheduledAt));
    }

    private function createSmsNotification(Appointment $appointment): void
    {
        $recipient = $appointment->patient->phone ?? $appointment->patient->contact_email;

        if (blank($recipient)) {
            return;
        }

        SmsNotification::query()->create([
            'appointment_id' => $appointment->id,
            'notification_status_id' => NotificationStatus::query()->where('name', 'queued')->value('id'),
            'event' => 'appointment_scheduled',
            'recipient' => $recipient,
            'message' => "Your appointment {$appointment->appointment_number} is scheduled for {$appointment->scheduled_at->toDateTimeString()}.",
        ]);
    }
}
