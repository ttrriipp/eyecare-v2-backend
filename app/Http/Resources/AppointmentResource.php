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
            'visit_reason' => $this->visitReason->name,
            'status' => $this->status->name,
            'scheduled_at' => $this->scheduled_at->toISOString(),
            'contact_notes' => $this->contact_notes,
            'staff_notes' => $this->staff_notes,
        ];
    }
}
