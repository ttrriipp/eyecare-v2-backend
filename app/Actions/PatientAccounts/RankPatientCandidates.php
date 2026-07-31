<?php

namespace App\Actions\PatientAccounts;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Collection;

class RankPatientCandidates
{
    public function __construct(
        protected NormalizeContact $normalize,
        protected CreateContactLookupHash $lookupHash,
    ) {}

    /**
     * @return Collection<int, array{patient: Patient, strength: string, reasons: array<string>}>
     */
    public function handle(User $account): Collection
    {
        $candidates = collect();

        // Search by contact email lookup hash
        $emailHash = $this->getEmailHash($account);
        if ($emailHash !== null) {
            $patients = Patient::query()
                ->where('contact_email_lookup_hash', $emailHash)
                ->whereNull('user_id') // Only unlinked patients
                ->get();

            foreach ($patients as $patient) {
                $strength = $this->calculateStrength($account, $patient, $emailHash, null);
                $candidates->push([
                    'patient' => $patient,
                    'strength' => $strength['strength'],
                    'reasons' => $strength['reasons'],
                ]);
            }
        }

        // Search by phone lookup hash
        $phoneHash = $this->getPhoneHash($account);
        if ($phoneHash !== null) {
            $patients = Patient::query()
                ->where('phone_lookup_hash', $phoneHash)
                ->whereNull('user_id')
                ->get();

            foreach ($patients as $patient) {
                // Avoid duplicates
                if ($candidates->contains(fn ($c) => $c['patient']->id === $patient->id)) {
                    continue;
                }

                $strength = $this->calculateStrength($account, $patient, null, $phoneHash);
                $candidates->push([
                    'patient' => $patient,
                    'strength' => $strength['strength'],
                    'reasons' => $strength['reasons'],
                ]);
            }
        }

        // Search by name + date of birth
        if ($account->first_name && $account->last_name && $account->date_of_birth) {
            $normalizedName = $this->normalize->name($account->first_name.' '.$account->last_name);
            $dob = $account->date_of_birth->format('Y-m-d');

            $patients = Patient::query()
                ->whereNull('user_id')
                ->where('date_of_birth', $dob)
                ->get()
                ->filter(function ($patient) use ($normalizedName) {
                    $patientName = $this->normalize->name($patient->full_name);

                    return $patientName === $normalizedName;
                });

            foreach ($patients as $patient) {
                if ($candidates->contains(fn ($c) => $c['patient']->id === $patient->id)) {
                    continue;
                }

                $reasons = ['exact_name', 'exact_dob'];
                $candidates->push([
                    'patient' => $patient,
                    'strength' => 'moderate',
                    'reasons' => $reasons,
                ]);
            }
        }

        // Sort by strength (strong first)
        $strengthOrder = ['strong' => 0, 'moderate' => 1, 'weak' => 2];

        return $candidates->sortBy(fn ($c) => $strengthOrder[$c['strength']] ?? 3)->values();
    }

    protected function calculateStrength(User $account, Patient $patient, ?string $emailHash, ?string $phoneHash): array
    {
        $reasons = [];
        $score = 0;

        if ($emailHash !== null && $patient->contact_email_lookup_hash === $emailHash) {
            $reasons[] = 'exact_email';
            $score += 3;
        }

        if ($phoneHash !== null && $patient->phone_lookup_hash === $phoneHash) {
            $reasons[] = 'exact_phone';
            $score += 3;
        }

        if ($account->date_of_birth && $patient->date_of_birth
            && $account->date_of_birth->format('Y-m-d') === $patient->date_of_birth->format('Y-m-d')) {
            $reasons[] = 'exact_dob';
            $score += 2;
        }

        if ($account->first_name && $account->last_name) {
            $accountName = $this->normalize->name($account->first_name.' '.$account->last_name);
            $patientName = $this->normalize->name($patient->full_name);
            if ($accountName === $patientName) {
                $reasons[] = 'exact_name';
                $score += 1;
            }
        }

        $strength = 'weak';
        if ($score >= 5) {
            $strength = 'strong';
        } elseif ($score >= 3) {
            $strength = 'moderate';
        }

        return ['strength' => $strength, 'reasons' => $reasons];
    }

    protected function getEmailHash(User $account): ?string
    {
        $contact = $account->contacts()->where('type', 'email')->whereNotNull('verified_at')->first();
        if ($contact === null) {
            return null;
        }

        return $contact->lookup_hash;
    }

    protected function getPhoneHash(User $account): ?string
    {
        $contact = $account->contacts()->where('type', 'phone')->whereNotNull('verified_at')->first();
        if ($contact === null) {
            return null;
        }

        return $contact->lookup_hash;
    }
}
