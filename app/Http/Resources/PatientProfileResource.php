<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read string $patient_number
 *
 * @mixin User
 */
class PatientProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $patient = $this->patient;

        return [
            'id' => $this->id,
            'patient_number' => $patient?->patient_number,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->name,
            'full_name' => $patient?->full_name,
            'date_of_birth' => $patient?->date_of_birth?->toDateString(),
            'occupation' => $patient?->occupation,
            'address' => $patient?->address,
            'gender' => $patient?->gender,
            'contact_email' => $patient?->contact_email,
        ];
    }
}
