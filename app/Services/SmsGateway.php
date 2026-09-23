<?php

namespace App\Services;

interface SmsGateway
{
    public function isEnabled(): bool;

    public function send(string $recipient, string $message): bool;

    public function failureReason(): ?string;

    public function isRetryableFailure(): bool;

    public function providerReference(): ?string;

    public function providerStatus(): ?string;
}
