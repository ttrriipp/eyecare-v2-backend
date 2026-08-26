<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkAppointmentNoShow
{
    public function handle(
        Appointment $appointment,
        User $actor,
        ?string $staffNotes = null,
    ): Appointment {
        if ($appointment->status->name !== 'scheduled') {
            throw ValidationException::withMessages([
                'appointment' => ['Only scheduled appointments can be marked as no-show.'],
            ]);
        }

        // Cannot mark no-show before the scheduled start time
        if ($appointment->scheduled_at->isFuture()) {
            throw ValidationException::withMessages([
                'appointment' => ['Cannot mark as no-show before the scheduled appointment time.'],
            ]);
        }

        return DB::transaction(function () use ($appointment, $actor, $staffNotes): Appointment {
            $noShowStatus = AppointmentStatus::query()
                ->where('name', 'no_show')
                ->firstOrFail();

            $appointment->update([
                'appointment_status_id' => $noShowStatus->id,
                'no_show_by' => $actor->id,
                'no_show_at' => now(),
                'staff_notes' => $staffNotes ?? $appointment->staff_notes,
            ]);

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $appointment,
                action: AuditEvent::AppointmentNoShow->value,
                metadata: array_filter([
                    'actor_id' => $actor->id,
                    'staff_notes' => $staffNotes,
                ]),
                actorId: $actor->id,
            );

            return $appointment->fresh(['appointmentType', 'status', 'patient']);
        });
    }
}
