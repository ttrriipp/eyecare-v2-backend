<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appointment_number' => $this->appointment_number,
            'appointment_type' => $this->appointmentType?->name,
            'duration_minutes' => $this->duration_minutes,
            'referring_source' => $this->referring_source,
            'status' => $this->status->name,
            'scheduled_at' => $this->scheduled_at->toISOString(),
            'contact_notes' => $this->contact_notes,
            'last_reschedule_reason' => $this->last_reschedule_reason,
            'source' => $this->source,
            'assigned_optometrist' => $this->optometrist ? ['name' => $this->optometrist->name] : null,
        ];
    }
}
