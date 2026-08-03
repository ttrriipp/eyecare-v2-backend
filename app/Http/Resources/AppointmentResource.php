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
            'checked_in_at' => $this->checked_in_at?->toISOString(),
            'fulfilled_at' => $this->fulfilled_at?->toISOString(),
            'contact_notes' => $this->contact_notes,
            'source' => $this->source,
            'assigned_optometrist' => $this->optometrist ? ['name' => $this->optometrist->full_name] : null,
            'latest_reschedule' => $this->whenLoaded('latestReschedule') && $this->latestReschedule
                ? [
                    'previous_scheduled_at' => $this->latestReschedule->previous_scheduled_at->toISOString(),
                    'new_scheduled_at' => $this->latestReschedule->new_scheduled_at->toISOString(),
                    'initiated_by' => $this->latestReschedule->initiated_by,
                    'reason_category' => $this->latestReschedule->reason_category,
                    'reason_details' => $this->latestReschedule->reason_details,
                    'rescheduled_at' => $this->latestReschedule->rescheduled_at->toISOString(),
                ]
                : null,
            'cancellation' => $this->cancelled_at
                ? [
                    'initiated_by' => $this->cancelled_by,
                    'reason_category' => $this->cancellation_reason_category,
                    'reason_details' => $this->cancellation_reason_details,
                    'cancelled_at' => $this->cancelled_at->toISOString(),
                ]
                : null,
            'no_show_at' => $this->no_show_at?->toISOString(),
        ];
    }
}
