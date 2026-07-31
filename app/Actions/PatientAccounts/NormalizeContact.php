<?php

namespace App\Actions\PatientAccounts;

use InvalidArgumentException;

class NormalizeContact
{
    public function email(string $value): string
    {
        $normalized = mb_strtolower(trim($value));

        if (! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address: {$value}");
        }

        return $normalized;
    }

    public function phone(string $value): string
    {
        $digits = preg_replace('/\D/', '', trim($value));

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '+63'.substr($digits, 1);
        }

        if (strlen($digits) === 10 && ! str_starts_with($digits, '0')) {
            return '+63'.$digits;
        }

        throw new InvalidArgumentException("Invalid Philippine phone number: {$value}");
    }

    public function name(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($value)));
    }

    public function dateOfBirth(string $value): string
    {
        return (new \DateTimeImmutable($value))->format('Y-m-d');
    }
}
