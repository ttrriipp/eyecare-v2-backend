<?php

namespace App\Actions\Patients;

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\NormalizeContact;
use App\Models\Patient;
use Illuminate\Support\Collection;

class SearchPatientDuplicates
{
    public function __construct(
        protected NormalizeContact $normalize,
        protected CreateContactLookupHash $lookupHash,
    ) {}

    /**
     * @return Collection<int, Patient>
     */
    public function handle(array $data): Collection
    {
        $candidates = collect();

        // Search by email
        if (! empty($data['contact_email'])) {
            try {
                $hash = $this->lookupHash->forEmail($data['contact_email']);
                $patients = Patient::query()
                    ->where('contact_email_lookup_hash', $hash)
                    ->get();
                $candidates = $candidates->merge($patients);
            } catch (\InvalidArgumentException) {
                // Invalid email format, skip search
            }
        }

        // Search by phone
        if (! empty($data['phone'])) {
            try {
                $hash = $this->lookupHash->forPhone($data['phone']);
                $patients = Patient::query()
                    ->where('phone_lookup_hash', $hash)
                    ->get();
                $candidates = $candidates->merge($patients);
            } catch (\InvalidArgumentException) {
                // Invalid phone format, skip search
            }
        }

        // Search by name + DOB
        if (! empty($data['full_name']) && ! empty($data['date_of_birth'])) {
            $normalizedName = $this->normalize->name($data['full_name']);
            $dob = $data['date_of_birth'];

            $patients = Patient::query()
                ->where('date_of_birth', $dob)
                ->get()
                ->filter(fn ($p) => $this->normalize->name($p->full_name) === $normalizedName);

            $candidates = $candidates->merge($patients);
        }

        return $candidates->unique('id')->values();
    }
}
