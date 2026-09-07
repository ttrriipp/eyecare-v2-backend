<?php

namespace App\Http\Resources;

use App\Models\AppointmentRescheduleRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AppointmentRescheduleRequest
 */
class AppointmentRescheduleRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isDetail = $request->routeIs('api.v1.appointment-reschedule-requests.show');

        return [
            'id' => $this->id,
            'request_number' => $this->request_number,
            'appointment_id' => $this->appointment_id,
            'status' => $this->effectiveStatus()->value,
            'current_scheduled_at' => $this->current_scheduled_at?->toIso8601String(),
            'requested_scheduled_at' => $this->requested_scheduled_at?->toIso8601String(),
            'alternative_scheduled_times' => $this->alternative_scheduled_times ?? [],
            'selected_scheduled_at' => $this->selected_scheduled_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'reason_details' => $this->when($isDetail, $this->encrypted_reason_details),
        ];
    }
}
