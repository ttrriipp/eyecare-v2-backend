<?php

namespace App\Actions\PatientAccounts;

use App\Models\User;

final class PatientLinkIdentitySnapshot
{
    public function __construct(
        private readonly NormalizeContact $normalizeContact,
    ) {}

    /**
     * @return array{first_name: ?string, middle_name: ?string, last_name: ?string, date_of_birth: ?string}
     */
    public function fromAccount(User $account): array
    {
        return [
            'first_name' => $this->trimNullable($account->first_name),
            'middle_name' => $this->trimNullable($account->middle_name),
            'last_name' => $this->trimNullable($account->last_name),
            'date_of_birth' => $account->date_of_birth?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    public function matchesAccount(?array $snapshot, User $account): bool
    {
        if ($snapshot === null) {
            return false;
        }

        $current = $this->fromAccount($account);

        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            if ($this->normalizeName($snapshot[$field] ?? null) !== $this->normalizeName($current[$field])) {
                return false;
            }
        }

        return ($snapshot['date_of_birth'] ?? null) === $current['date_of_birth'];
    }

    private function trimNullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeName(mixed $value): ?string
    {
        $value = $this->trimNullable($value);

        return $value === null ? null : $this->normalizeContact->name($value);
    }
}
