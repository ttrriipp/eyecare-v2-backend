<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class PatientAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $primaryEmail = $this->when(
            $this->relationLoaded('contacts'),
            fn () => $this->contacts
                ->where('type', 'email')
                ->where('is_primary', true)
                ->whereNotNull('verified_at')
                ->first()?->encrypted_value
        );

        $primaryPhone = $this->when(
            $this->relationLoaded('contacts'),
            fn () => $this->contacts
                ->where('type', 'phone')
                ->where('is_primary', true)
                ->whereNotNull('verified_at')
                ->first()?->encrypted_value
        );

        $linkStatus = 'unlinked';
        if ($this->relationLoaded('patient') && $this->patient !== null) {
            $linkStatus = 'linked';
        } elseif ($this->relationLoaded('linkRequests') && $this->linkRequests->where('status', 'pending')->isNotEmpty()) {
            $linkStatus = 'pending_review';
        }

        $linkedPatient = null;
        if ($linkStatus === 'linked' && $this->relationLoaded('patient') && $this->patient !== null) {
            $p = $this->patient;
            $linkedPatient = [
                'patient_number' => $p->patient_number,
                'full_name' => $p->full_name,
                'date_of_birth' => $p->date_of_birth?->toDateString(),
                'gender' => $p->gender,
                'occupation' => $p->occupation,
                'address' => $p->address,
                'phone' => $p->phone,
                'contact_email' => $p->contact_email,
            ];
        }

        return [
            // Account fields (editable via PATCH /me where noted)
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'email' => $primaryEmail,
            'phone' => $primaryPhone,
            'role' => $this->when($this->relationLoaded('role'), fn () => $this->role->name),
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'link_status' => $linkStatus,
            'privacy_policy_version' => $this->privacy_notice_version,
            'privacy_accepted_at' => $this->privacy_acknowledged_at?->toISOString(),

            // Linked patient section (read-only clinical demographics)
            'linked_patient' => $linkedPatient,
        ];
    }
}
