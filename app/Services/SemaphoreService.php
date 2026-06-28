<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SemaphoreService
{
    public function send(string $recipient, string $message): bool
    {
        if (! config('services.semaphore.enabled')) {
            return true;
        }

        $response = Http::post('https://api.semaphore.co/api/v4/messages', [
            'apikey' => config('services.semaphore.api_key'),
            'number' => $recipient,
            'message' => $message,
            'sendername' => config('services.semaphore.sender_name'),
        ]);

        return $response->successful();
    }
}
