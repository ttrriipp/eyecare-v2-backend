<?php

namespace App\Services;

interface SmsGateway
{
    public function isEnabled(): bool;

    public function send(string $recipient, string $message): bool;
}
