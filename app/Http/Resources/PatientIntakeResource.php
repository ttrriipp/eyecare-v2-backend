<?php

namespace App\Http\Resources;

use App\Models\PatientIntake;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PatientIntake
 */
class PatientIntakeResource extends JsonResource
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
            'patient_id' => $this->patient_id,
            'appointment_id' => $this->appointment_id,
            'status' => $this->status?->value,
            'appointment_type' => $this->appointment_type,
            'full_name' => $this->full_name,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender,
            'occupation' => $this->occupation,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'chief_complaint' => $this->chief_complaint,
            'past_ocular_history' => $this->past_ocular_history,
            'past_surgical_history' => $this->past_surgical_history,
            'past_medical_history' => $this->past_medical_history,
            'allergies' => $this->allergies,
            'medications' => $this->medications,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
