<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => $this->name,
            'email' => $this->when($this->relationLoaded('contacts'), fn () => $this->contacts->where('type', 'email')->where('is_primary', true)->first()?->encrypted_value),
            'phone' => $this->when($this->relationLoaded('contacts'), fn () => $this->contacts->where('type', 'phone')->where('is_primary', true)->first()?->encrypted_value),
            'role' => $this->when($this->relationLoaded('role'), fn () => $this->role->name),
            'date_of_birth' => $this->date_of_birth,
            'privacy_notice_version' => $this->privacy_notice_version,
            'privacy_acknowledged_at' => $this->privacy_acknowledged_at?->toISOString(),
        ];
    }
}
