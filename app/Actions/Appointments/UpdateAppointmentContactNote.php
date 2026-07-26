<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use Illuminate\Validation\ValidationException;

class UpdateAppointmentContactNote
{
    public function handle(Appointment $appointment, ?string $contactNotes): Appointment
    {
        if (! in_array($appointment->status->name, ['pending', 'confirmed'], true)) {
            throw ValidationException::withMessages([
                'appointment' => ['This appointment cannot be edited.'],
            ]);
        }

        $normalizedContactNotes = is_string($contactNotes) ? trim($contactNotes) : null;

        $appointment->update([
            'contact_notes' => $normalizedContactNotes === '' ? null : $normalizedContactNotes,
        ]);

        return $appointment->fresh(['appointmentType', 'status', 'optometrist']);
    }
}
