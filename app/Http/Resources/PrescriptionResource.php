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
            'previous_prescription_id' => $this->previous_prescription_id,
            'is_current' => ! (bool) $this->next_prescription_exists,
            'date' => $this->prescribed_at?->toDateString(),
            'measurements' => [
                'main' => [
                    'od' => [
                        'value' => $this->main_od_value,
                        'sphere' => $this->main_od_sphere,
                        'cylinder' => $this->main_od_cylinder,
                    ],
                    'os' => [
                        'value' => $this->main_os_value,
                        'sphere' => $this->main_os_sphere,
                        'cylinder' => $this->main_os_cylinder,
                    ],
                ],
                'add' => [
                    'od' => [
                        'value' => $this->add_od_value,
                        'sphere' => $this->add_od_sphere,
                        'cylinder' => $this->add_od_cylinder,
                    ],
                    'os' => [
                        'value' => $this->add_os_value,
                        'sphere' => $this->add_os_sphere,
                        'cylinder' => $this->add_os_cylinder,
                    ],
                ],
            ],
            'remarks' => $this->remarks,
        ];
    }
}
