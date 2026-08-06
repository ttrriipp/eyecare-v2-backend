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

        return $request->fresh();
    }
}
