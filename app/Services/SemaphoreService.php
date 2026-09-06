<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SemaphoreService implements SmsGateway
{
    public function send(string $recipient, string $message): bool
    {
        if (! config('services.semaphore.enabled')) {
            Log::info('SMS delivery skipped (Semaphore disabled)', [
                'driver' => 'semaphore',
            ]);

            return true;
        }

        $response = Http::timeout((int) config('services.semaphore.timeout', 10))
            ->retry((int) config('services.semaphore.retries', 2), 100, throw: false)
            ->post((string) config('services.semaphore.endpoint', 'https://api.semaphore.co/api/v4/messages'), [
                'apikey' => config('services.semaphore.api_key'),
                'number' => $recipient,
                'message' => $message,
                'sendername' => config('services.semaphore.sender_name'),
            ]);

        return $response->successful();
    }
}
