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
        $middleName = $account->middle_name;
        $lastName = $account->last_name;
        $dateOfBirth = $account->date_of_birth?->format('Y-m-d');

        return $this->rankCandidates(
            emailHash: $emailHash,
            phoneHash: $phoneHash,
            firstName: $firstName,
            middleName: $middleName,
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
            middleName: $snapshot['middle_name'] ?? null,
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
        ?string $middleName,
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
                $strength = $this->calculateStrength($patient, $emailHash, null, $firstName, $middleName, $lastName, $dateOfBirth);
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

                $strength = $this->calculateStrength($patient, null, $phoneHash, $firstName, $middleName, $lastName, $dateOfBirth);
                $candidates->push([
                    'patient' => $patient,
                    'strength' => $strength['strength'],
                    'reasons' => $strength['reasons'],
                ]);
            }
        }

        // Search by name + date of birth
        if ($firstName && $lastName && $dateOfBirth) {
            $patients = Patient::query()
                ->whereNull('user_id')
                ->where('date_of_birth', $dateOfBirth)
                ->get()
                ->filter(function (Patient $patient) use ($firstName, $middleName, $lastName): bool {
                    return $this->namesMatch($patient, $firstName, $middleName, $lastName);
                });

            foreach ($patients as $patient) {
                if ($candidates->contains(fn ($c) => $c['patient']->id === $patient->id)) {
                    continue;
                }

                $strength = $this->calculateStrength($patient, null, null, $firstName, $middleName, $lastName, $dateOfBirth);
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
        ?string $middleName,
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

        if ($this->namesMatch($patient, $firstName, $middleName, $lastName)) {
            $reasons[] = 'exact_name';
            $score += 1;
        }

        $strength = 'weak';
        if ($score >= 5) {
            $strength = 'strong';
        } elseif ($score >= 3) {
            $strength = 'moderate';
        }

        return ['strength' => $strength, 'reasons' => $reasons];
    }

    protected function namesMatch(Patient $patient, ?string $firstName, ?string $middleName, ?string $lastName): bool
    {
        if (blank($firstName) || blank($lastName)) {
            return false;
        }

        $submittedName = $this->normalize->name(implode(' ', array_filter([
            $firstName,
            $middleName,
            $lastName,
        ])));

        if ($submittedName === $this->normalize->name($patient->full_name)) {
            return true;
        }

        if (blank($middleName)) {
            return $submittedName === $this->normalize->name($patient->first_name.' '.$patient->last_name);
        }

        return false;
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
