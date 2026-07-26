<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CreateWalkInAppointment
{
    public function handle(
        Patient $patient,
        AppointmentType $appointmentType,
        User $staff,
        ?User $optometrist = null,
        ?string $contactNotes = null,
        ?string $referringSource = null,
    ): Appointment {
        if ($optometrist !== null && ! User::query()->optometrists()->whereKey($optometrist)->exists()) {
            throw ValidationException::withMessages([
                'optometrist_id' => ['The selected user is not an optometrist.'],
            ]);
        }

        return Appointment::query()->create([
            'patient_id' => $patient->id,
            'created_by' => $staff->id,
            'optometrist_id' => $optometrist?->id,
            'appointment_type_id' => $appointmentType->id,
            'duration_minutes' => $appointmentType->duration_minutes,
            'referring_source' => $referringSource,
            'appointment_status_id' => AppointmentStatus::query()->where('name', 'arrived')->value('id'),
            'source' => 'walk_in',
            'scheduled_at' => now(),
            'checked_in_at' => now(),
            'checked_in_by' => $staff->id,
            'contact_notes' => $contactNotes,
        ]);
    }
}
