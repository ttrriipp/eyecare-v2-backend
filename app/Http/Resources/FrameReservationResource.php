<?php

namespace App\Http\Resources;

use App\Http\Controllers\Api\FrameReservationController;
use App\Models\FrameReservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FrameReservation
 */
class FrameReservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appointment_id' => $this->appointment_id,
            'is_held' => $this->isHeld(),
            'expires_at' => FrameReservationController::deriveExpiresAt($this),
            'created_at' => $this->created_at->toIso8601String(),
            'appointment' => AppointmentContextResource::make($this->whenLoaded('appointment')),
            'items' => FrameReservationItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
