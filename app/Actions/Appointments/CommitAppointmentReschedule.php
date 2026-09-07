<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\Appointment;
use App\Models\AppointmentReschedule;
use App\Models\AppointmentStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommitAppointmentReschedule
{
    public function __construct(
        private readonly ScheduleAppointment $scheduleAppointment,
        private readonly LockAppointmentScheduleDate $lockAppointmentScheduleDate,
        private readonly CreateAuditLog $createAuditLog,
    ) {}

    public function handle(
        Appointment $appointment,
        CarbonInterface $scheduledAt,
        string $initiator,
        ?User $actor = null,
        ?string $reasonCategory = null,
        ?string $reasonDetails = null,
    ): AppointmentReschedule {
        $reasonDetails = filled($reasonDetails) ? trim($reasonDetails) : null;

        $history = DB::transaction(function () use (
            $appointment,
            $scheduledAt,
            $initiator,
            $actor,
            $reasonCategory,
            $reasonDetails,
        ): AppointmentReschedule {
            $lockedAppointment = Appointment::query()
                ->with(['appointmentType', 'optometrist', 'patient', 'status'])
                ->lockForUpdate()
                ->findOrFail($appointment->getKey());

            $this->validateReschedule(
                appointment: $lockedAppointment,
                initiator: $initiator,
                reasonCategory: $reasonCategory,
                reasonDetails: $reasonDetails,
            );

            $this->lockScheduleDates($lockedAppointment, $scheduledAt);

            try {
                $this->scheduleAppointment->handle(
                    scheduledAt: $scheduledAt,
                    durationMinutes: $lockedAppointment->duration_minutes,
                    optometrist: $lockedAppointment->optometrist,
                    ignoreAppointment: $lockedAppointment,
                    enforceGrid: true,
                );
            } catch (ValidationException $exception) {
                $this->throwStructuredSlotUnavailable($exception, $lockedAppointment, $scheduledAt);
            }

            $previousScheduledAt = $lockedAppointment->scheduled_at->toDateTimeString();
            $attributes = ['scheduled_at' => $scheduledAt];

            if ($initiator === 'patient') {
                $attributes['appointment_status_id'] = AppointmentStatus::query()
                    ->where('name', 'scheduled')
                    ->value('id');
            }

            $lockedAppointment->update($attributes);

            $history = AppointmentReschedule::query()->create([
                'appointment_id' => $lockedAppointment->id,
                'previous_scheduled_at' => $previousScheduledAt,
                'new_scheduled_at' => $lockedAppointment->fresh()->scheduled_at,
                'initiated_by' => $initiator,
                'actor_id' => $actor?->id,
                'reason_category' => $reasonCategory,
                'reason_details' => $reasonDetails,
                'rescheduled_at' => now(),
            ]);

            $this->createAuditLog->handle(
                subject: $lockedAppointment,
                action: AuditEvent::AppointmentRescheduled,
                metadata: array_filter([
                    'from' => $previousScheduledAt,
                    'to' => $lockedAppointment->scheduled_at->toDateTimeString(),
                    'initiator' => $initiator,
                    'actor_id' => $actor?->id,
                    'reason_category' => $reasonCategory,
                    'reason' => $reasonDetails,
                ], fn ($value): bool => $value !== null),
                actorId: $actor?->id,
            );

            return $history->fresh([
                'appointment.patient.account',
                'appointment.appointmentType',
                'appointment.status',
                'appointment.optometrist',
            ]);
        }, attempts: 3);

        /** @var Appointment $updatedAppointment */
        $updatedAppointment = $history->appointment;
        $appointment->setRawAttributes($updatedAppointment->getAttributes(), true);
        $appointment->setRelations($updatedAppointment->getRelations());

        return $history;
    }

    private function validateReschedule(
        Appointment $appointment,
        string $initiator,
        ?string $reasonCategory,
        ?string $reasonDetails,
    ): void {
        if (! in_array($appointment->status?->name, ['scheduled'], true)) {
            throw ValidationException::withMessages([
                'appointment' => ['This appointment cannot be rescheduled.'],
            ]);
        }

        if (! in_array($initiator, ['patient', 'clinic'], true)) {
            throw ValidationException::withMessages([
                'initiator' => ['The reschedule initiator is invalid.'],
            ]);
        }

        if ($initiator === 'clinic' && blank($reasonCategory)) {
            throw ValidationException::withMessages([
                'reason_category' => ['A reason category is required for clinic-initiated rescheduling.'],
            ]);
        }

        if ($reasonCategory === 'other' && blank($reasonDetails)) {
            throw ValidationException::withMessages([
                'reschedule_reason' => ['Please provide details when selecting "other" as the reason.'],
            ]);
        }
    }

    private function lockScheduleDates(Appointment $appointment, CarbonInterface $scheduledAt): void
    {
        $dates = [
            $appointment->scheduled_at->copy()->setTimezone(config('app.timezone'))->toDateString(),
            $scheduledAt->copy()->setTimezone(config('app.timezone'))->toDateString(),
        ];

        sort($dates);

        foreach (array_unique($dates) as $date) {
            $this->lockAppointmentScheduleDate->handle($date);
        }
    }

    private function throwStructuredSlotUnavailable(
        ValidationException $exception,
        Appointment $appointment,
        CarbonInterface $scheduledAt,
    ): never {
        $scheduledAtErrors = $exception->errors()['scheduled_at'] ?? [];

        if (! in_array('This time slot is not available. Please choose another time.', $scheduledAtErrors, true)) {
            throw $exception;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'This time slot is no longer available. Please choose another time.',
            'code' => 'SLOT_UNAVAILABLE',
            'errors' => [
                'scheduled_at' => [
                    'This time slot is no longer available. Please choose another time.',
                ],
            ],
            'availability' => [
                'date' => $scheduledAt->copy()->setTimezone(config('app.timezone'))->toDateString(),
                'appointment_type_id' => $appointment->appointment_type_id,
                'optometrist_id' => $appointment->optometrist_id,
                'appointment_id' => $appointment->id,
            ],
        ], 422));
    }
}
