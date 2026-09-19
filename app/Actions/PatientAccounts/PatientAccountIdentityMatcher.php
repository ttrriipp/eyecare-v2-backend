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

        // Verified contact: at least one matching blind index. A verified
        // contact on either side without a same-type match is a mismatch;
        // absent contact evidence is reported separately as missing.
        $contactResult = $this->matchesVerifiedContact($account, $patient);

        if ($contactResult === 'matched') {
            $matched[] = 'verified_contact';
        } elseif ($contactResult === 'mismatched') {
            $mismatched[] = 'verified_contact';
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
     * Evaluate an immutable appointment identity snapshot against a Patient
     * record. Snapshots contain only the same PII-safe evidence used by the
     * account matcher, including a blind contact hash.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function handleSnapshot(array $snapshot, Patient $patient): PatientAccountIdentityMatch
    {
        $matched = [];
        $mismatched = [];
        $missing = [];

        $this->compareNameField($snapshot['first_name'] ?? null, $patient->first_name, 'first_name', $matched, $mismatched, $missing);
        $this->compareNameField($snapshot['last_name'] ?? null, $patient->last_name, 'last_name', $matched, $mismatched, $missing);

        $snapshotDob = is_string($snapshot['date_of_birth'] ?? null)
            ? $snapshot['date_of_birth']
            : null;
        $patientDob = $patient->date_of_birth?->toDateString();

        if ($snapshotDob === null || $patientDob === null) {
            $missing[] = 'date_of_birth';
        } elseif ($snapshotDob === $patientDob) {
            $matched[] = 'date_of_birth';
        } else {
            $mismatched[] = 'date_of_birth';
        }

        $snapshotMiddle = self::normalize(is_string($snapshot['middle_name'] ?? null) ? $snapshot['middle_name'] : null);
        $patientMiddle = self::normalize($patient->middle_name);

        if ($snapshotMiddle !== '' && $patientMiddle !== '') {
            if ($snapshotMiddle === $patientMiddle) {
                $matched[] = 'middle_name';
            } else {
                $mismatched[] = 'middle_name';
            }
        }

        $contactType = is_string($snapshot['verified_contact_type'] ?? null)
            ? $snapshot['verified_contact_type']
            : null;
        $snapshotHash = is_string($snapshot['verified_contact_hash'] ?? null)
            ? $snapshot['verified_contact_hash']
            : null;
        $patientHash = match ($contactType) {
            'phone' => $patient->phone_lookup_hash,
            'email' => $patient->contact_email_lookup_hash,
            default => null,
        };

        if ($snapshotHash === null || $patientHash === null) {
            $missing[] = 'verified_contact';
        } elseif (hash_equals($patientHash, $snapshotHash)) {
            $matched[] = 'verified_contact';
        } else {
            $mismatched[] = 'verified_contact';
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
     * @param  list<string>  $matched
     * @param  list<string>  $mismatched
     * @param  list<string>  $missing
     */
    private function compareNameField(
        mixed $left,
        ?string $right,
        string $field,
        array &$matched,
        array &$mismatched,
        array &$missing,
    ): void {
        $left = is_string($left) ? self::normalize($left) : '';
        $right = self::normalize($right);

        if ($left === '' || $right === '') {
            $missing[] = $field;
        } elseif ($left === $right) {
            $matched[] = $field;
        } else {
            $mismatched[] = $field;
        }
    }

    /**
     * Check if at least one verified account contact matches a Patient contact.
     */
    private function matchesVerifiedContact(User $account, Patient $patient): string
    {
        $verifiedContacts = PatientAccountContact::query()
            ->where('user_id', $account->id)
            ->whereNotNull('verified_at')
            ->get(['type', 'lookup_hash']);

        $patientHashes = [
            'phone' => $patient->phone_lookup_hash,
            'email' => $patient->contact_email_lookup_hash,
        ];

        $hasComparableEvidence = false;

        foreach ($verifiedContacts as $contact) {
            $patientHash = $patientHashes[$contact->type] ?? null;

            if ($patientHash === null) {
                continue;
            }

            $hasComparableEvidence = true;

            if (hash_equals($patientHash, (string) $contact->lookup_hash)) {
                return 'matched';
            }
        }

        return $hasComparableEvidence ? 'mismatched' : 'missing';
    }
}
