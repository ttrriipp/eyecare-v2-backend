<?php

namespace App\Actions\Encounters;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Encounter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckInAppointment
{
    public function handle(Appointment $appointment): Encounter
    {
        if (! in_array($appointment->status->name, ['scheduled'], true)) {
            throw ValidationException::withMessages([
                'appointment' => ['Only scheduled appointments can be checked in.'],
            ]);
        }

        return DB::transaction(function () use ($appointment): Encounter {
            // Lock the appointment row to prevent concurrent check-ins
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->first();

            // Re-validate after lock
            if (! in_array($lockedAppointment->status->name, ['scheduled'], true)) {
                throw ValidationException::withMessages([
                    'appointment' => ['This appointment has already been processed.'],
                ]);
            }

            // Update appointment status to checked_in
            $arrivedStatus = AppointmentStatus::query()
                ->where('name', 'checked_in')
                ->firstOrFail();

            $lockedAppointment->update([
                'appointment_status_id' => $arrivedStatus->id,
                'checked_in_at' => now(),
                'checked_in_by' => auth()->id(),
            ]);

            // Return existing encounter if already checked in
            $existingEncounter = Encounter::query()
                ->where('appointment_id', $lockedAppointment->id)
                ->first();

            if ($existingEncounter !== null) {
                return $existingEncounter;
            }

            // Create encounter linked to the appointment
            // Copy assigned provider and prefill chief complaint from appointment reason
            $encounter = Encounter::query()->create([
                'patient_id' => $lockedAppointment->patient_id,
                'appointment_id' => $lockedAppointment->id,
                'optometrist_id' => $lockedAppointment->optometrist_id,
                'status' => EncounterStatus::Planned,
                'chief_complaint' => $lockedAppointment->reason_for_visit,
            ]);

            // Audit the check-in event
            app(CreateAuditLog::class)->handle(
                subject: $encounter,
                action: AuditEvent::AppointmentCheckedIn->value,
                metadata: [
                    'appointment_id' => $lockedAppointment->id,
                    'patient_id' => $lockedAppointment->patient_id,
                ],
            );

            return $encounter;
        });
    }
}
