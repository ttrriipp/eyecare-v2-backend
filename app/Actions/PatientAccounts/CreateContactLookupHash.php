<?php

namespace App\Actions\PatientAccounts;

class CreateContactLookupHash
{
    public function __construct(
        protected NormalizeContact $normalize,
    ) {}

    public function forEmail(string $email): string
    {
        $normalized = $this->normalize->email($email);

        return hash_hmac('sha256', 'email:'.$normalized, $this->lookupKey());
    }

    public function forPhone(string $phone): string
    {
        $normalized = $this->normalize->phone($phone);

        return hash_hmac('sha256', 'phone:'.$normalized, $this->lookupKey());
    }

    public function forName(string $name): string
    {
        $normalized = $this->normalize->name($name);

        return hash_hmac('sha256', 'name:'.$normalized, $this->lookupKey());
    }

    protected function lookupKey(): string
    {
        $key = config('patient_accounts.contact_lookup_key');

        if (empty($key)) {
            throw new \RuntimeException('Patient account contact lookup key is not configured.');
        }

        return $key;
    }
}
