<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

        return DB::transaction(function () use ($patient, $appointmentType, $staff, $optometrist, $contactNotes, $referringSource): Appointment {
            $appointment = Appointment::query()->create([
                'patient_id' => $patient->id,
                'created_by' => $staff->id,
                'optometrist_id' => $optometrist?->id,
                'appointment_type_id' => $appointmentType->id,
                'duration_minutes' => $appointmentType->duration_minutes,
                'referring_source' => $referringSource,
                'appointment_status_id' => AppointmentStatus::query()->where('name', 'checked_in')->value('id'),
                'source' => 'walk_in',
                'scheduled_at' => now(),
                'checked_in_at' => now(),
                'checked_in_by' => $staff->id,
                'contact_notes' => $contactNotes,
            ]);

            // Create the planned encounter for the walk-in
            $encounter = Encounter::query()->create([
                'patient_id' => $patient->id,
                'appointment_id' => $appointment->id,
                'optometrist_id' => $optometrist?->id,
                'status' => EncounterStatus::Planned,
            ]);

            app(CreateAuditLog::class)->handle(
                subject: $encounter,
                action: AuditEvent::AppointmentCheckedIn->value,
                metadata: [
                    'appointment_id' => $appointment->id,
                    'patient_id' => $patient->id,
                    'walk_in' => true,
                ],
                actorId: $staff->id,
            );

            return $appointment;
        });
    }
}
