<?php

namespace App\Actions\Appointments;

use App\Models\PatientAccountContact;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class BuildAppointmentRequestIdentitySnapshot
{
    /**
     * Build an encrypted identity snapshot for an unlinked appointment request.
     *
     * Returns null for linked accounts. For unlinked accounts, uses the submitted
     * identity or falls back to the account profile. Derives the verified primary
     * contact server-side.
     *
     * @param  array{first_name?: string, middle_name?: ?string, last_name?: string, date_of_birth?: string}  $submittedIdentity
     * @return array{first_name: string, middle_name: ?string, last_name: string, date_of_birth: string, verified_contact_type: string, verified_contact_masked: string, verified_contact_hash: string, submitted_at: string}|null
     */
    public function handle(User $account, ?array $submittedIdentity): ?array
    {
        // Linked accounts use the authoritative Patient record
        if ($account->patient !== null) {
            if ($submittedIdentity !== null) {
                throw ValidationException::withMessages([
                    'identity' => ['Identity cannot be provided for a linked account.'],
                ]);
            }

            return null;
        }

        // Build effective identity from submission or account fallback
        $firstName = $submittedIdentity['first_name'] ?? $account->first_name;
        $middleName = $submittedIdentity['middle_name'] ?? $account->middle_name;
        $lastName = $submittedIdentity['last_name'] ?? $account->last_name;
        $dateOfBirth = $submittedIdentity['date_of_birth'] ?? $account->date_of_birth?->format('Y-m-d');

        // Normalize whitespace
        $firstName = $this->normalize($firstName);
        $middleName = $this->normalize($middleName);
        $lastName = $this->normalize($lastName);

        // Validate required fields
        if (blank($firstName)) {
            throw ValidationException::withMessages([
                'identity.first_name' => ['First name is required for unlinked accounts.'],
            ]);
        }

        if (blank($lastName)) {
            throw ValidationException::withMessages([
                'identity.last_name' => ['Last name is required for unlinked accounts.'],
            ]);
        }

        if (blank($dateOfBirth)) {
            throw ValidationException::withMessages([
                'identity.date_of_birth' => ['Date of birth is required for unlinked accounts.'],
            ]);
        }

        // Validate date of birth is in the past
        try {
            $dob = Carbon::parse($dateOfBirth);
            if (! $dob->isPast()) {
                throw ValidationException::withMessages([
                    'identity.date_of_birth' => ['Date of birth must be in the past.'],
                ]);
            }
        } catch (\Exception $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }
            throw ValidationException::withMessages([
                'identity.date_of_birth' => ['Invalid date of birth format.'],
            ]);
        }

        // Get the verified primary contact
        $contact = $this->resolveVerifiedPrimaryContact($account);

        return [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'date_of_birth' => $dob->format('Y-m-d'),
            'verified_contact_type' => $contact['type'],
            'verified_contact_masked' => $contact['masked'],
            'verified_contact_hash' => $contact['hash'],
            'submitted_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Resolve the account's one verified primary contact.
     *
     * @return array{type: string, masked: string, hash: string}
     */
    private function resolveVerifiedPrimaryContact(User $account): array
    {
        $contact = PatientAccountContact::query()
            ->where('user_id', $account->id)
            ->whereNotNull('verified_at')
            ->where('is_primary', true)
            ->first();

        if ($contact === null) {
            // Fall back to any verified contact if no primary
            $contact = PatientAccountContact::query()
                ->where('user_id', $account->id)
                ->whereNotNull('verified_at')
                ->first();
        }

        if ($contact === null) {
            throw ValidationException::withMessages([
                'identity' => ['No verified contact found on this account.'],
            ]);
        }

        // Check for ambiguous primary contacts
        $primaryCount = PatientAccountContact::query()
            ->where('user_id', $account->id)
            ->whereNotNull('verified_at')
            ->where('is_primary', true)
            ->count();

        if ($primaryCount > 1) {
            throw ValidationException::withMessages([
                'identity' => ['Multiple primary verified contacts found. Please contact support.'],
            ]);
        }

        return [
            'type' => $contact->type,
            'masked' => $this->maskContact($contact->type, $contact->encrypted_value),
            'hash' => $contact->lookup_hash,
        ];
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return blank($trimmed) ? null : $trimmed;
    }

    private function maskContact(string $type, string $value): string
    {
        if ($type === 'email') {
            $parts = explode('@', $value);
            if (count($parts) === 2) {
                return substr($parts[0], 0, 1).'***@'.$parts[1];
            }

            return '***';
        }

        // Phone masking
        if (strlen($value) >= 4) {
            return substr($value, 0, 3).'***'.substr($value, -3);
        }

        return '***';
    }
}
