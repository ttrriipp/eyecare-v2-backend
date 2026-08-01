<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read int $id
 * @property-read string $name
 * @property-read string|null $first_name
 * @property-read string|null $middle_name
 * @property-read string|null $last_name
 * @property-read string|null $email
 * @property-read string|null $phone
 * @property-read string $role
 * @property-read string|null $date_of_birth
 * @property-read string|null $privacy_notice_version
 * @property-read string|null $privacy_acknowledged_at
 *
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

        return [
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
        ];
    }
}
