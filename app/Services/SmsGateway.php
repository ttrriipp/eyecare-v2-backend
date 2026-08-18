<?php

namespace App\Services;

interface SmsGateway
{
    public function send(string $recipient, string $message): bool;
}
