<?php

namespace App\Actions\PatientAccounts;

use App\Models\Patient;
use App\Models\PatientAccountContact;
use App\Models\User;

/**
 * Deterministic identity compatibility check between a User account and a Patient.
 *
 * This is the single source of truth for link eligibility and drift detection.
 * Candidate ranking and fuzzy search are not authorization evidence.
 */
final class PatientAccountIdentityMatcher
{
    /**
     * Evaluate identity compatibility between a User and a Patient.
     */
    public function handle(User $account, Patient $patient): PatientAccountIdentityMatch
    {
        $matched = [];
        $mismatched = [];
        $missing = [];

        // Required: first name
        $accountFirst = self::normalize($account->first_name);
        $patientFirst = self::normalize($patient->first_name);

        if ($accountFirst === '' || $patientFirst === '') {
            $missing[] = 'first_name';
        } elseif ($accountFirst === $patientFirst) {
            $matched[] = 'first_name';
        } else {
            $mismatched[] = 'first_name';
        }

        // Required: last name
        $accountLast = self::normalize($account->last_name);
        $patientLast = self::normalize($patient->last_name);

        if ($accountLast === '' || $patientLast === '') {
            $missing[] = 'last_name';
        } elseif ($accountLast === $patientLast) {
            $matched[] = 'last_name';
        } else {
            $mismatched[] = 'last_name';
        }

        // Required: date of birth
        $accountDob = $account->date_of_birth?->toDateString();
        $patientDob = $patient->date_of_birth?->toDateString();

        if ($accountDob === null || $patientDob === null) {
            $missing[] = 'date_of_birth';
        } elseif ($accountDob === $patientDob) {
            $matched[] = 'date_of_birth';
        } else {
            $mismatched[] = 'date_of_birth';
        }

        // Optional: middle name — neutral if missing on either side
        $accountMiddle = self::normalize($account->middle_name);
        $patientMiddle = self::normalize($patient->middle_name);

        if ($accountMiddle !== '' && $patientMiddle !== '') {
            if ($accountMiddle === $patientMiddle) {
                $matched[] = 'middle_name';
            } else {
                $mismatched[] = 'middle_name';
            }
        }

        // Verified contact: at least one matching blind index
        $contactMatched = $this->matchesVerifiedContact($account, $patient);

        if ($contactMatched) {
            $matched[] = 'verified_contact';
        } else {
            $missing[] = 'verified_contact';
        }

        return new PatientAccountIdentityMatch(
            matchedFields: $matched,
            mismatchedFields: $mismatched,
            missingFields: $missing,
        );
    }

    /**
     * Normalize a name for comparison: trim, collapse whitespace, lowercase.
     */
    private static function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }

    /**
     * Check if at least one verified account contact matches a Patient contact.
     */
    private function matchesVerifiedContact(User $account, Patient $patient): bool
    {
        // Check verified phone
        $accountPhone = PatientAccountContact::query()
            ->where('user_id', $account->id)
            ->where('type', 'phone')
            ->whereNotNull('verified_at')
            ->first();

        if ($accountPhone !== null && $patient->phone_lookup_hash !== null) {
            if ($accountPhone->lookup_hash === $patient->phone_lookup_hash) {
                return true;
            }
        }

        // Check verified email
        $accountEmail = PatientAccountContact::query()
            ->where('user_id', $account->id)
            ->where('type', 'email')
            ->whereNotNull('verified_at')
            ->first();

        if ($accountEmail !== null && $patient->contact_email_lookup_hash !== null) {
            if ($accountEmail->lookup_hash === $patient->contact_email_lookup_hash) {
                return true;
            }
        }

        return false;
    }
}
