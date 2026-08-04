<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateScheduledAppointment
{
    public function __construct(
        private readonly EvaluateAppointmentAvailability $evaluateAppointmentAvailability,
        private readonly LockAppointmentScheduleDate $lockAppointmentScheduleDate,
    ) {}

    public function handle(
        Patient $patient,
        AppointmentType $appointmentType,
        CarbonInterface $scheduledAt,
        ?User $optometrist = null,
        ?string $contactNotes = null,
        ?string $referringSource = null,
        ?string $reasonForVisit = null,
    ): Appointment {
        return DB::transaction(function () use ($patient, $appointmentType, $scheduledAt, $optometrist, $contactNotes, $referringSource, $reasonForVisit): Appointment {
            $this->lockAppointmentScheduleDate->handle($scheduledAt);
            $this->validateOptometrist($optometrist);

            $decision = $this->evaluateAppointmentAvailability->handle(
                startsAt: $scheduledAt,
                durationMinutes: $appointmentType->duration_minutes,
                optometrist: $optometrist,
                enforceFuture: true,
                enforceGrid: true,
            );

            if (! $decision->available) {
                $this->throwForUnavailableDecision($decision, $appointmentType, $optometrist);
            }

            $appointment = Appointment::query()->create([
                'patient_id' => $patient->id,
                'appointment_type_id' => $appointmentType->id,
                'duration_minutes' => $appointmentType->duration_minutes,
                'referring_source' => $referringSource,
                'optometrist_id' => $optometrist?->id,
                'appointment_status_id' => AppointmentStatus::query()->where('name', 'scheduled')->value('id'),
                'source' => 'mobile',
                'scheduled_at' => $scheduledAt,
                'contact_notes' => $contactNotes,
                'reason_for_visit' => $reasonForVisit,
            ]);

            return $appointment->fresh(['appointmentType', 'status', 'patient', 'optometrist']);
        }, attempts: 3);
    }

    private function validateOptometrist(?User $optometrist): void
    {
        if ($optometrist === null) {
            return;
        }

        $isEligible = User::query()->optometrists()->whereKey($optometrist)->exists();

        if (! $isEligible) {
            throw ValidationException::withMessages([
                'optometrist_id' => ['The selected user is not an optometrist.'],
            ]);
        }
    }

    private function throwForUnavailableDecision(
        AppointmentAvailabilityDecision $decision,
        AppointmentType $appointmentType,
        ?User $optometrist,
    ): never {
        if ($decision->reason === 'capacity_reached') {
            throw new HttpResponseException(response()->json([
                'message' => 'This time slot is no longer available. Please choose another time.',
                'code' => 'SLOT_UNAVAILABLE',
                'errors' => [
                    'scheduled_at' => [
                        'This time slot is no longer available. Please choose another time.',
                    ],
                ],
                'availability' => [
                    'date' => $decision->startsAt->toDateString(),
                    'appointment_type_id' => $appointmentType->id,
                    'optometrist_id' => $optometrist?->id,
                    'appointment_id' => null,
                ],
            ], 422));
        }

        throw ValidationException::withMessages([
            'scheduled_at' => [$this->messageForReason($decision->reason)],
        ]);
    }

    private function messageForReason(?string $reason): string
    {
        return match ($reason) {
            'elapsed' => 'The appointment must be scheduled in the future.',
            'clinic_closed' => 'The clinic is closed on the selected day.',
            'outside_clinic_hours' => 'The appointment must fit within clinic hours.',
            'outside_slot_grid' => 'Please choose one of the available appointment times.',
            default => 'This time slot is not available. Please choose another time.',
        };
    }
}
