<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelAppointment
{
    public function handle(
        Appointment $appointment,
        string $initiator,
        ?User $actor = null,
        ?string $reasonCategory = null,
        ?string $reasonDetails = null,
    ): Appointment {
        $currentStatus = $appointment->status->name;

        if (! in_array($currentStatus, ['scheduled', 'checked_in'], true)) {
            throw ValidationException::withMessages([
                'appointment' => ["Cannot cancel a {$currentStatus} appointment."],
            ]);
        }

        // Clinic cancellation requires a reason
        if ($initiator === 'clinic' && blank($reasonCategory)) {
            throw ValidationException::withMessages([
                'reason_category' => ['A reason is required for clinic-initiated cancellation.'],
            ]);
        }

        // 'other' requires details
        if ($reasonCategory === 'other' && blank($reasonDetails)) {
            throw ValidationException::withMessages([
                'reason_details' => ['Please provide details when selecting "other" as the reason.'],
            ]);
        }

        return DB::transaction(function () use ($appointment, $initiator, $actor, $reasonCategory, $reasonDetails, $currentStatus): Appointment {
            $cancelledStatus = AppointmentStatus::query()
                ->where('name', 'cancelled')
                ->firstOrFail();

            $appointment->update([
                'appointment_status_id' => $cancelledStatus->id,
                'cancelled_by' => $initiator,
                'cancelled_by_user_id' => $actor?->id,
                'cancellation_reason_category' => $reasonCategory,
                'cancellation_reason_details' => $reasonDetails,
                'cancelled_at' => now(),
            ]);

            // Cancel planned encounter if exists
            if ($currentStatus === 'checked_in') {
                $encounter = $appointment->encounter;

                if ($encounter !== null && $encounter->status === EncounterStatus::Planned) {
                    $encounter->update([
                        'status' => EncounterStatus::Cancelled,
                    ]);
                }
            }

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $appointment,
                action: AuditEvent::AppointmentCancelled->value,
                metadata: array_filter([
                    'initiator' => $initiator,
                    'actor_id' => $actor?->id,
                    'reason_category' => $reasonCategory,
                    'reason_details' => $reasonDetails,
                    'cancelled_encounter' => $currentStatus === 'checked_in',
                ]),
                actorId: $actor?->id,
            );

            return $appointment->fresh(['appointmentType', 'status', 'patient']);
        });
    }
}
