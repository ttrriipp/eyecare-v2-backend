<?php

namespace App\Http\Resources;

use App\Actions\Appointments\ClinicSchedule;
use App\Models\FrameReservation;
use Carbon\Carbon;
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
            'expires_at' => $this->deriveExpiresAt(),
            'created_at' => $this->created_at->toIso8601String(),
            'appointment' => AppointmentContextResource::make($this->whenLoaded('appointment')),
            'items' => FrameReservationItemResource::collection($this->whenLoaded('items')),
        ];
    }

    private function deriveExpiresAt(): ?string
    {
        $appointmentDate = $this->appointment?->scheduled_at;

        if ($appointmentDate === null) {
            return null;
        }

        $schedule = ClinicSchedule::forDate($appointmentDate);

        return Carbon::parse($appointmentDate->toDateString().' '.$schedule->closeTime)->toIso8601String();
    }
}
