<?php

namespace App\Http\Resources;

use App\Models\AppointmentTypeVisitReasonPreset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AppointmentTypeVisitReasonPreset
 */
class VisitReasonPresetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
        ];
    }
}
