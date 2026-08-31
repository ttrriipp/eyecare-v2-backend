<?php

namespace App\Http\Resources;

use App\Models\AppointmentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin AppointmentType
 */
class AppointmentTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $visitReasonPresets = $this->relationLoaded('activeVisitReasonPresets')
            ? $this->activeVisitReasonPresets
            : new Collection;

        return [
            'id' => $this->id,
            'name' => $this->patient_label ?? $this->name,
            'description' => $this->patient_description,
            'duration_minutes' => $this->duration_minutes,
            'requires_referral' => $this->requires_referral,
            'visit_reason_presets' => VisitReasonPresetResource::collection($visitReasonPresets),
        ];
    }
}
