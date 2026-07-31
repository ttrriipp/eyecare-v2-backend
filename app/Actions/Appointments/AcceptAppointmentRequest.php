<?php

namespace App\Actions\Appointments;

use App\Enums\AppointmentRequestStatus;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptAppointmentRequest
{
    public function handle(
        AppointmentRequest $request,
        User $reviewer,
        ?int $appointmentTypeId = null,
        ?string $adjustedScheduledAt = null,
    ): Appointment {
        // Idempotent: return existing appointment if already accepted
        if ($request->status === AppointmentRequestStatus::Accepted && $request->appointment_id !== null) {
            return $request->appointment;
        }

        if ($request->status !== AppointmentRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'request' => ['Only pending appointment requests can be accepted.'],
            ]);
        }

        if ($request->patient_id === null) {
            throw ValidationException::withMessages([
                'request' => ['Patient must be resolved before accepting the request.'],
            ]);
        }

        // Determine appointment type
        $appointmentType = $appointmentTypeId !== null
            ? AppointmentType::findOrFail($appointmentTypeId)
            : AppointmentType::where('name', 'New Patient')->firstOrFail();

        $scheduledAt = $adjustedScheduledAt !== null
            ? Carbon::parse($adjustedScheduledAt)
            : $request->scheduled_at;

        return DB::transaction(function () use ($request, $reviewer, $appointmentType, $scheduledAt) {
            // Lock the request
            $request->lockForUpdate();

            if ($request->status !== AppointmentRequestStatus::Pending) {
                // Already processed (idempotent)
                if ($request->appointment_id !== null) {
                    return $request->appointment;
                }
                throw ValidationException::withMessages([
                    'request' => ['This request has already been processed.'],
                ]);
            }

            // Create the appointment
            $scheduledStatus = AppointmentStatus::where('name', 'scheduled')->firstOrFail();

            $appointment = Appointment::create([
                'patient_id' => $request->patient_id,
                'appointment_type_id' => $appointmentType->id,
                'appointment_status_id' => $scheduledStatus->id,
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $appointmentType->duration_minutes,
                'source' => 'mobile',
                'reason_for_visit' => $request->encrypted_reason_for_visit,
                'contact_notes' => null,
                'staff_notes' => null,
            ]);

            // Update the request
            $request->update([
                'status' => AppointmentRequestStatus::Accepted,
                'appointment_id' => $appointment->id,
                'appointment_type_id' => $appointmentType->id,
                'resolved_by_user_id' => $reviewer->id,
                'resolved_at' => now(),
            ]);

            return $appointment->load(['appointmentType', 'status', 'patient']);
        });
    }
}
