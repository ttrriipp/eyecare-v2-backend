<?php

namespace App\Actions\Appointments;

use App\Actions\PatientAccounts\NormalizeContact;
use App\Models\PatientAccountContact;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BuildAppointmentRequestIdentitySnapshot
{
    public function __construct(
        protected NormalizeContact $normalizeContact,
    ) {}

    /**
     * Build an encrypted identity snapshot for an unlinked appointment request.
     *
     * Returns null for linked accounts. For unlinked accounts, uses the submitted
     * identity or falls back to the account profile. Derives the verified phone
     * server-side and only accepts a submitted phone as a confirmation of it.
     *
     * @param  array{phone?: string, email?: ?string, first_name?: string, middle_name?: ?string, last_name?: string, date_of_birth?: string, gender?: string, occupation?: string, address?: string}|null  $submittedIdentity
     * @return array{phone: string, email: ?string, first_name: string, middle_name: ?string, last_name: string, date_of_birth: string, gender: ?string, occupation: ?string, address: ?string, verified_contact_type: string, verified_contact_masked: string, verified_contact_hash: string, submitted_at: string}|null
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

        // Build effective identity from submission or account fallback.
        $firstName = $this->valueFromIdentity($submittedIdentity, 'first_name', $account->first_name);
        $middleName = $this->valueFromIdentity($submittedIdentity, 'middle_name', $account->middle_name);
        $lastName = $this->valueFromIdentity($submittedIdentity, 'last_name', $account->last_name);
        $dateOfBirth = $this->valueFromIdentity($submittedIdentity, 'date_of_birth', $account->date_of_birth?->format('Y-m-d'));
        $email = $this->valueFromIdentity($submittedIdentity, 'email', $account->email);
        $gender = $this->valueFromIdentity($submittedIdentity, 'gender', $account->getAttribute('gender'));
        $occupation = $this->valueFromIdentity($submittedIdentity, 'occupation', $account->getAttribute('occupation'));
        $address = $this->valueFromIdentity($submittedIdentity, 'address', $account->address);

        // Normalize whitespace
        $firstName = $this->normalize($firstName);
        $middleName = $this->normalize($middleName);
        $lastName = $this->normalize($lastName);
        $gender = $this->normalize($gender);
        $occupation = $this->normalize($occupation);
        $address = $this->normalize($address);

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
        $phone = $this->resolveVerifiedPhone($account);

        if (array_key_exists('phone', $submittedIdentity ?? [])) {
            $this->validateSubmittedPhone($submittedIdentity['phone'], $phone);
        }

        return [
            'phone' => $phone,
            'email' => $this->normalizeEmail($email),
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'date_of_birth' => $dob->format('Y-m-d'),
            'gender' => $gender,
            'occupation' => $occupation,
            'address' => $address,
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

    private function resolveVerifiedPhone(User $account): string
    {
        $contact = PatientAccountContact::query()
            ->where('user_id', $account->id)
            ->where('type', 'phone')
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->first();

        if ($contact === null) {
            throw ValidationException::withMessages([
                'identity.phone' => ['No verified phone found on this account.'],
            ]);
        }

        try {
            return $this->normalizeContact->phone($contact->encrypted_value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'identity.phone' => ['The verified account phone is invalid.'],
            ]);
        }
    }

    private function validateSubmittedPhone(string $submittedPhone, string $verifiedPhone): void
    {
        try {
            $normalizedPhone = $this->normalizeContact->phone($submittedPhone);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'identity.phone' => ['Phone must be a valid Philippine phone number.'],
            ]);
        }

        if ($normalizedPhone !== $verifiedPhone) {
            throw ValidationException::withMessages([
                'identity.phone' => ['Phone must match the verified account phone.'],
            ]);
        }
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $normalized = $this->normalize($value);

        if ($normalized === null) {
            return null;
        }

        try {
            return $this->normalizeContact->email($normalized);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'identity.email' => ['Email must be a valid email address.'],
            ]);
        }
    }

    private function valueFromIdentity(?array $identity, string $key, mixed $fallback): mixed
    {
        if ($identity !== null && array_key_exists($key, $identity)) {
            return $identity[$key];
        }

        return $fallback;
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
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
