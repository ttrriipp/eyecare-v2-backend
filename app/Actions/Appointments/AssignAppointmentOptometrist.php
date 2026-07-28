<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignAppointmentOptometrist
{
    public function handle(
        Appointment $appointment,
        User $optometrist,
    ): Appointment {
        if (! in_array($appointment->status?->name, ['scheduled'], true)) {
            throw ValidationException::withMessages([
                'appointment' => ['Optometrist can only be assigned to scheduled appointments.'],
            ]);
        }

        if (! $optometrist->is_optometrist) {
            throw ValidationException::withMessages([
                'optometrist_id' => ['The selected user is not an optometrist.'],
            ]);
        }

        return DB::transaction(function () use ($appointment, $optometrist): Appointment {
            $appointment->update([
                'optometrist_id' => $optometrist->id,
            ]);

            app(CreateAuditLog::class)->handle(
                subject: $appointment,
                action: 'appointment.optometrist_assigned',
                metadata: [
                    'optometrist_id' => $optometrist->id,
                ],
            );

            return $appointment->fresh(['appointmentType', 'status', 'patient', 'optometrist']);
        });
    }
}
