<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use App\Models\VisitReason;
use Illuminate\Validation\ValidationException;

class CreateWalkInAppointment
{
    public function handle(
        User $customer,
        VisitReason $visitReason,
        User $staff,
        ?User $optometrist = null,
        ?string $contactNotes = null,
    ): Appointment {
        if ($customer->role->name !== 'customer') {
            throw ValidationException::withMessages([
                'customer_id' => ['The selected user is not a patient.'],
            ]);
        }

        if ($optometrist !== null && ! User::query()->optometrists()->whereKey($optometrist)->exists()) {
            throw ValidationException::withMessages([
                'optometrist_id' => ['The selected user is not an optometrist.'],
            ]);
        }

        return Appointment::query()->create([
            'customer_id' => $customer->id,
            'created_by' => $staff->id,
            'optometrist_id' => $optometrist?->id,
            'visit_reason_id' => $visitReason->id,
            'appointment_status_id' => AppointmentStatus::query()->where('name', 'arrived')->value('id'),
            'source' => 'walk_in',
            'scheduled_at' => now(),
            'checked_in_at' => now(),
            'checked_in_by' => $staff->id,
            'contact_notes' => $contactNotes,
        ]);
    }
}
