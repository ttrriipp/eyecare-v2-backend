<?php

namespace App\Actions\Encounters;

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

            // Create encounter linked to the appointment
            $encounter = Encounter::query()->create([
                'patient_id' => $appointment->patient_id,
                'appointment_id' => $appointment->id,
                'status' => EncounterStatus::Waiting,
            ]);

            return $encounter;
        });
    }
}
