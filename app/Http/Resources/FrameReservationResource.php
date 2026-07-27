<?php

namespace App\Http\Resources;

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
            'status' => $this->status->value,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'items' => FrameReservationItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
