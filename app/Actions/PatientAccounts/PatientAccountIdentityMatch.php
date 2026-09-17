<?php

namespace App\Actions\PatientAccounts;

/**
 * PII-safe result of comparing a User account to a Patient record.
 *
 * Reason codes contain field names only, never raw values.
 */
final class PatientAccountIdentityMatch
{
    /**
     * @param  list<string>  $matchedFields
     * @param  list<string>  $mismatchedFields
     * @param  list<string>  $missingFields
     */
    public function __construct(
        public readonly array $matchedFields,
        public readonly array $mismatchedFields,
        public readonly array $missingFields,
    ) {}

    public function isEligible(): bool
    {
        return empty($this->mismatchedFields) && empty($this->missingFields);
    }

    /**
     * @return array{matched_fields: list<string>, mismatched_fields: list<string>, missing_fields: list<string>}
     */
    public function toArray(): array
    {
        return [
            'matched_fields' => $this->matchedFields,
            'mismatched_fields' => $this->mismatchedFields,
            'missing_fields' => $this->missingFields,
        ];
    }
}
