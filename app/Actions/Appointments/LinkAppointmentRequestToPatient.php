<?php

namespace App\Actions\Appointments;

use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use App\Models\Patient;
use Illuminate\Validation\ValidationException;

class LinkAppointmentRequestToPatient
{
    public function handle(AppointmentRequest $request, Patient $patient): AppointmentRequest
    {
        if ($request->status !== AppointmentRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'request' => ['Only pending appointment requests can be linked to a patient.'],
            ]);
        }

        if ($request->patient_id !== null) {
            throw ValidationException::withMessages([
                'request' => ['This request is already linked to a patient.'],
            ]);
        }

        $request->update(['patient_id' => $patient->id]);

        // Also link the patient account if the request has a user and the patient is not already linked
        if ($request->user_id !== null && $patient->user_id === null) {
            $patient->update(['user_id' => $request->user_id]);
        }

        return $request->fresh();
    }
}
