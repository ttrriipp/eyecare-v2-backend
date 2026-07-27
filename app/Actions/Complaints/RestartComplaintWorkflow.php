<?php

namespace App\Actions\Complaints;

use App\Enums\ComplaintStatus;
use App\Enums\EncounterStatus;
use App\Models\Appointment;
use App\Models\Complaint;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestartComplaintWorkflow
{
    /**
     * Link a complaint to a new appointment/encounter for re-examination.
     *
     * The original encounter, prescription, job order, and invoice remain unchanged.
     */
    public function handle(
        Complaint $complaint,
        Appointment $newAppointment,
        User $staff,
    ): Complaint {
        if ($complaint->status === ComplaintStatus::Closed || $complaint->status === ComplaintStatus::Resolved) {
            throw ValidationException::withMessages([
                'complaint' => ['Cannot restart workflow for a resolved or closed complaint.'],
            ]);
        }

        return DB::transaction(function () use ($complaint, $newAppointment): Complaint {
            // Create a new encounter linked to the new appointment
            $encounter = Encounter::query()->create([
                'patient_id' => $complaint->patient_id,
                'appointment_id' => $newAppointment->id,
                'status' => EncounterStatus::Planned,
            ]);

            $complaint->update([
                'new_appointment_id' => $newAppointment->id,
                'new_encounter_id' => $encounter->id,
                'status' => ComplaintStatus::UnderReview,
            ]);

            return $complaint->fresh();
        });
    }
}
