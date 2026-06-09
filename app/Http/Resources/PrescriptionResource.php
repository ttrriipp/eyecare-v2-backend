<?php

namespace App\Http\Resources;

use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Prescription
 */
class PrescriptionResource extends JsonResource
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
            'appointment_id' => $this->appointment_id,
            'od_sphere' => $this->od_sphere,
            'od_cylinder' => $this->od_cylinder,
            'od_axis' => $this->od_axis,
            'od_add' => $this->od_add,
            'od_prism' => $this->od_prism,
            'od_base' => $this->od_base,
            'os_sphere' => $this->os_sphere,
            'os_cylinder' => $this->os_cylinder,
            'os_axis' => $this->os_axis,
            'os_add' => $this->os_add,
            'os_prism' => $this->os_prism,
            'os_base' => $this->os_base,
            'pd' => $this->pd,
            'prescribed_at' => $this->prescribed_at->toDateString(),
            'expires_at' => $this->expires_at->toDateString(),
            'notes' => $this->notes,
        ];
    }
}
