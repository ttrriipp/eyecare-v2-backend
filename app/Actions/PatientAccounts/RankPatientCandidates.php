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
     * Rank candidates from a User account (for Patient Link Requests).
     *
     * @return Collection<int, array{patient: Patient, strength: string, reasons: array<string>}>
     */
    public function handle(User $account): Collection
    {
        $emailHash = $this->getEmailHash($account);
        $phoneHash = $this->getPhoneHash($account);
        $firstName = $account->first_name;
        $lastName = $account->last_name;
        $dateOfBirth = $account->date_of_birth?->format('Y-m-d');

        return $this->rankCandidates(
            emailHash: $emailHash,
            phoneHash: $phoneHash,
            firstName: $firstName,
            lastName: $lastName,
            dateOfBirth: $dateOfBirth,
        );
    }

    /**
     * Rank candidates from an immutable identity snapshot (for Appointment Requests).
     *
     * @param  array{first_name: string, middle_name?: ?string, last_name: string, date_of_birth: string, verified_contact_hash: string}  $snapshot
     * @return Collection<int, array{patient: Patient, strength: string, reasons: array<string>}>
     */
    public function fromSnapshot(array $snapshot): Collection
    {
        $phoneHash = $snapshot['verified_contact_hash'] ?? null;
        $emailHash = null;

        // Determine contact type from snapshot
        if (($snapshot['verified_contact_type'] ?? null) === 'email') {
            $emailHash = $phoneHash;
            $phoneHash = null;
        }

        return $this->rankCandidates(
            emailHash: $emailHash,
            phoneHash: $phoneHash,
            firstName: $snapshot['first_name'] ?? null,
            lastName: $snapshot['last_name'] ?? null,
            dateOfBirth: $snapshot['date_of_birth'] ?? null,
        );
    }

    /**
     * Core ranking logic using normalized contact/name/DOB matching.
     *
     * @return Collection<int, array{patient: Patient, strength: string, reasons: array<string>}>
     */
    protected function rankCandidates(
        ?string $emailHash,
        ?string $phoneHash,
        ?string $firstName,
        ?string $lastName,
        ?string $dateOfBirth,
    ): Collection {
        $candidates = collect();

        // Search by email lookup hash
        if ($emailHash !== null) {
            $patients = Patient::query()
                ->where('contact_email_lookup_hash', $emailHash)
                ->whereNull('user_id')
                ->get();

            foreach ($patients as $patient) {
                $strength = $this->calculateStrength($patient, $emailHash, null, $firstName, $lastName, $dateOfBirth);
                $candidates->push([
                    'patient' => $patient,
                    'strength' => $strength['strength'],
                    'reasons' => $strength['reasons'],
                ]);
            }
        }

        // Search by phone lookup hash
        if ($phoneHash !== null) {
            $patients = Patient::query()
                ->where('phone_lookup_hash', $phoneHash)
                ->whereNull('user_id')
                ->get();

            foreach ($patients as $patient) {
                if ($candidates->contains(fn ($c) => $c['patient']->id === $patient->id)) {
                    continue;
                }

                $strength = $this->calculateStrength($patient, null, $phoneHash, $firstName, $lastName, $dateOfBirth);
                $candidates->push([
                    'patient' => $patient,
                    'strength' => $strength['strength'],
                    'reasons' => $strength['reasons'],
                ]);
            }
        }

        // Search by name + date of birth
        if ($firstName && $lastName && $dateOfBirth) {
            $normalizedName = $this->normalize->name($firstName.' '.$lastName);

            $patients = Patient::query()
                ->whereNull('user_id')
                ->where('date_of_birth', $dateOfBirth)
                ->get()
                ->filter(function ($patient) use ($normalizedName) {
                    $patientName = $this->normalize->name($patient->full_name);

                    return $patientName === $normalizedName;
                });

            foreach ($patients as $patient) {
                if ($candidates->contains(fn ($c) => $c['patient']->id === $patient->id)) {
                    continue;
                }

                $strength = $this->calculateStrength($patient, null, null, $firstName, $lastName, $dateOfBirth);
                $candidates->push([
                    'patient' => $patient,
                    'strength' => $strength['strength'],
                    'reasons' => $strength['reasons'],
                ]);
            }
        }

        $strengthOrder = ['strong' => 0, 'moderate' => 1, 'weak' => 2];

        return $candidates->sortBy(fn ($c) => $strengthOrder[$c['strength']] ?? 3)->values();
    }

    protected function calculateStrength(
        Patient $patient,
        ?string $emailHash,
        ?string $phoneHash,
        ?string $firstName,
        ?string $lastName,
        ?string $dateOfBirth,
    ): array {
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

        if ($dateOfBirth && $patient->date_of_birth
            && $dateOfBirth === $patient->date_of_birth->format('Y-m-d')) {
            $reasons[] = 'exact_dob';
            $score += 2;
        }

        if ($firstName && $lastName) {
            $normalizedName = $this->normalize->name($firstName.' '.$lastName);
            $patientName = $this->normalize->name($patient->full_name);
            if ($normalizedName === $patientName) {
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
