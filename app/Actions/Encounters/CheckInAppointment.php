<?php

namespace App\Actions\Encounters;

use App\Enums\EncounterStatus;
use App\Enums\IntakeStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Encounter;
use App\Models\PatientIntake;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckInAppointment
{
    public function handle(Appointment $appointment): Encounter
    {
        if (! in_array($appointment->status->name, ['pending', 'confirmed'], true)) {
            throw ValidationException::withMessages([
                'appointment' => ['Only pending or confirmed appointments can be checked in.'],
            ]);
        }

        return DB::transaction(function () use ($appointment): Encounter {
            // Update appointment status to arrived
            $arrivedStatus = AppointmentStatus::query()
                ->where('name', 'arrived')
                ->firstOrFail();

            $appointment->update([
                'appointment_status_id' => $arrivedStatus->id,
                'checked_in_at' => now(),
                'checked_in_by' => auth()->id(),
            ]);

            // Snapshot the verified intake for this encounter
            $verifiedIntake = PatientIntake::query()
                ->where('patient_id', $appointment->patient_id)
                ->where('status', IntakeStatus::Verified)
                ->when($appointment->id, fn ($query) => $query
                    ->where('appointment_id', $appointment->id)
                    ->orWhereNull('appointment_id'))
                ->latest('verified_at')
                ->first();

            // Create encounter linked to the appointment and intake
            $encounter = Encounter::query()->create([
                'patient_id' => $appointment->patient_id,
                'appointment_id' => $appointment->id,
                'patient_intake_id' => $verifiedIntake?->id,
                'status' => EncounterStatus::Waiting,
            ]);

            return $encounter;
        });
    }
}
