<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Notifications\NotifyAdminUsers;
use App\Actions\Notifications\NotifyPatientAccount;
use App\Enums\AppointmentRequestKind;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Enums\AuditEvent;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentReschedule;
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
        private readonly NotifyAdminUsers $notifyAdminUsers,
        private readonly NotifyPatientAccount $notifyPatientAccount,
    ) {}

    /**
     * Accept an appointment request, creating a confirmed appointment.
     *
     * Requires final type, duration, and start. A provider may be assigned
     * now or left unassigned for later. Uses schedule-date lock and retries
     * on deadlock.
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

        if ($request->isRebooking()) {
            return $this->acceptRebooking(
                request: $request,
                reviewer: $reviewer,
                scheduledAt: $scheduledAt,
                contactNote: $contactNote,
            );
        }

        if (! $request->isPending()) {
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

        // Validate the optometrist when one was selected.
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

    private function acceptRebooking(
        AppointmentRequest $request,
        User $reviewer,
        CarbonInterface $scheduledAt,
        ?string $contactNote,
    ): Appointment {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => ['Only pending appointment requests can be accepted.'],
            ]);
        }

        if ($request->patient_id === null || $request->appointment_id === null) {
            throw ValidationException::withMessages([
                'request' => ['A rebooking request must be linked to a patient appointment.'],
            ]);
        }

        if (! $this->matchesSubmittedPreference($request, $scheduledAt) && blank($contactNote)) {
            throw ValidationException::withMessages([
                'contact_note' => ['A contact note is required when the final time differs from submitted preferences.'],
            ]);
        }

        $appointment = DB::transaction(function () use ($request, $reviewer, $scheduledAt, $contactNote): Appointment {
            $lockedRequest = AppointmentRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $lockedRequest->isPending()) {
                if ($lockedRequest->status === AppointmentRequestStatus::Accepted
                    && $lockedRequest->appointment_id !== null) {
                    return Appointment::query()
                        ->with(['appointmentType', 'status', 'optometrist', 'patient'])
                        ->findOrFail($lockedRequest->appointment_id);
                }

                throw ValidationException::withMessages([
                    'request' => ['This request has already been processed.'],
                ]);
            }

            if (! $lockedRequest->isRebooking()
                || $lockedRequest->patient_id === null
                || $lockedRequest->appointment_id === null) {
                throw ValidationException::withMessages([
                    'request' => ['A rebooking request must be linked to a patient appointment.'],
                ]);
            }

            $appointment = Appointment::query()
                ->with(['appointmentType', 'status', 'optometrist', 'patient'])
                ->lockForUpdate()
                ->findOrFail($lockedRequest->appointment_id);

            if ($appointment->patient_id !== $lockedRequest->patient_id) {
                throw ValidationException::withMessages([
                    'request' => ['The request patient does not match the appointment.'],
                ]);
            }

            if ($appointment->status?->name !== AppointmentStatusName::Scheduled->value) {
                throw ValidationException::withMessages([
                    'request' => ['Only scheduled appointments can be rebooked.'],
                ]);
            }

            if (! $appointment->scheduled_at->isFuture()) {
                throw ValidationException::withMessages([
                    'request' => ['Only future appointments can be rebooked.'],
                ]);
            }

            if ($lockedRequest->original_scheduled_at === null
                || ! $lockedRequest->original_scheduled_at->equalTo($appointment->scheduled_at)) {
                throw ValidationException::withMessages([
                    'request' => ['The appointment changed after this request was submitted. Please submit a new request.'],
                ]);
            }

            if ($scheduledAt->equalTo($appointment->scheduled_at)) {
                throw ValidationException::withMessages([
                    'scheduled_at' => ['The requested time must differ from the current appointment time.'],
                ]);
            }

            $durationMinutes = (int) ($appointment->duration_minutes
                ?? $appointment->appointmentType?->duration_minutes
                ?? 30);

            $this->lockScheduleDates($appointment, $scheduledAt);

            $optometrist = null;
            if ($appointment->optometrist_id !== null) {
                $optometrist = User::query()->lockForUpdate()->find($appointment->optometrist_id);

                if ($optometrist === null || ! $optometrist->is_active || ! $optometrist->isOptometrist()) {
                    throw ValidationException::withMessages([
                        'request' => ['The assigned optometrist is no longer available.'],
                    ]);
                }

                if (! $this->evaluateAvailability->isOptometristEligible(
                    $optometrist,
                    $scheduledAt,
                    $scheduledAt->copy()->addMinutes($durationMinutes),
                )) {
                    throw ValidationException::withMessages([
                        'scheduled_at' => ['The assigned optometrist is no longer available for this time.'],
                    ]);
                }
            }

            $decision = $this->evaluateAvailability->handle(
                startsAt: $scheduledAt,
                durationMinutes: $durationMinutes,
                optometrist: $optometrist,
                ignoreAppointment: $appointment,
                enforceFuture: true,
                enforceGrid: true,
            );

            if (! $decision->available) {
                throw ValidationException::withMessages([
                    'scheduled_at' => ["This time slot is no longer available ({$decision->reason})."],
                ]);
            }

            $previousScheduledAt = $appointment->scheduled_at->copy();
            $appointmentAttributes = ['scheduled_at' => $scheduledAt];

            if (filled($contactNote)) {
                $appointmentAttributes['contact_notes'] = trim($contactNote);
            }

            $appointment->update($appointmentAttributes);

            AppointmentReschedule::query()->create([
                'appointment_id' => $appointment->id,
                'previous_scheduled_at' => $previousScheduledAt,
                'new_scheduled_at' => $scheduledAt,
                'initiated_by' => 'patient',
                'actor_id' => $reviewer->id,
                'reason_category' => null,
                'reason_details' => null,
                'rescheduled_at' => now(),
            ]);

            $lockedRequest->update([
                'status' => AppointmentRequestStatus::Accepted,
                'selected_scheduled_at' => $scheduledAt,
                'resolved_by_user_id' => $reviewer->id,
                'resolved_at' => now(),
            ]);

            $this->createAuditLog->handle(
                subject: $lockedRequest,
                action: AuditEvent::AppointmentRequestAccepted,
                metadata: [
                    'appointment_id' => $appointment->id,
                    'appointment_type_id' => $appointment->appointment_type_id,
                    'patient_id' => $lockedRequest->patient_id,
                    'request_type' => AppointmentRequestKind::Reschedule->value,
                    'selected_scheduled_at' => $scheduledAt->toIso8601String(),
                ],
                actorId: $reviewer->id,
            );

            $this->createAuditLog->handle(
                subject: $appointment,
                action: AuditEvent::AppointmentRescheduled,
                metadata: [
                    'from' => $previousScheduledAt->toDateTimeString(),
                    'to' => $scheduledAt->toDateTimeString(),
                    'initiated_by' => 'patient',
                    'request_id' => $lockedRequest->id,
                ],
                actorId: $reviewer->id,
            );

            $appointment->load(['patient', 'appointmentType', 'status', 'optometrist']);
            $this->createRescheduledSmsNotification($appointment);
            $this->notifyPatientAccount->appointmentRescheduled($appointment);

            return $appointment->fresh(['patient', 'appointmentType', 'status', 'optometrist']);
        }, attempts: 3);

        $acceptedRequest = $request->fresh();
        $previousScheduledAt = $acceptedRequest->original_scheduled_at?->format('M d, Y g:i A')
            ?? $appointment->scheduled_at->format('M d, Y g:i A');

        $this->notifyAdminUsers->appointmentRescheduled($appointment, $previousScheduledAt);

        return $appointment;
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
            // Lock the request row
            $request = AppointmentRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $request->isPending()) {
                // Already processed (idempotent)
                if ($request->appointment_id !== null) {
                    return $request->appointment;
                }
                throw ValidationException::withMessages([
                    'request' => ['This request has already been processed.'],
                ]);
            }

            if ($request->patient_id === null) {
                throw ValidationException::withMessages([
                    'request' => ['Patient must be resolved before accepting the request.'],
                ]);
            }

            // Lock the schedule date after locking the request so reject/link
            // transitions cannot race the terminal acceptance state.
            $this->lockScheduleDate->handle($scheduledAt);

            if ($optometrist !== null) {
                $optometrist = User::query()->lockForUpdate()->find($optometrist->id);

                if ($optometrist === null || ! $optometrist->is_active || ! $optometrist->isOptometrist()) {
                    throw ValidationException::withMessages([
                        'optometrist_id' => ['The selected optometrist is no longer available.'],
                    ]);
                }

                // Recheck the provider interval under the lock.
                if (! $this->evaluateAvailability->isOptometristEligible($optometrist, $scheduledAt, $scheduledAt->copy()->addMinutes($durationMinutes))) {
                    throw ValidationException::withMessages([
                        'optometrist_id' => ['The selected optometrist is no longer available for this time slot.'],
                    ]);
                }
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
            $this->notifyPatientAccount->appointmentConfirmed($request, $appointment);

            return $appointment->load(['appointmentType', 'status', 'patient', 'optometrist']);
        });
    }

    private function matchesSubmittedPreference(AppointmentRequest $request, CarbonInterface $scheduledAt): bool
    {
        return collect($request->getAllTimePreferences())
            ->contains(fn (string $preference): bool => Carbon::parse($preference)->equalTo($scheduledAt));
    }

    private function lockScheduleDates(Appointment $appointment, CarbonInterface $scheduledAt): void
    {
        $dates = [
            $appointment->scheduled_at->copy()->setTimezone(config('app.timezone'))->toDateString(),
            $scheduledAt->copy()->setTimezone(config('app.timezone'))->toDateString(),
        ];

        sort($dates);

        foreach (array_unique($dates) as $date) {
            $this->lockScheduleDate->handle($date);
        }
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

    private function createRescheduledSmsNotification(Appointment $appointment): void
    {
        $recipient = $appointment->patient->phone ?? $appointment->patient->contact_email;

        if (blank($recipient)) {
            return;
        }

        SmsNotification::query()->create([
            'appointment_id' => $appointment->id,
            'notification_status_id' => NotificationStatus::query()->where('name', 'queued')->value('id'),
            'event' => 'appointment_rescheduled',
            'recipient' => $recipient,
            'message' => "Your appointment {$appointment->appointment_number} has been rescheduled to {$appointment->scheduled_at->toDateTimeString()}.",
        ]);
    }
}
