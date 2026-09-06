<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TextBeeService implements SmsGateway
{
    public function send(string $recipient, string $message): bool
    {
        if (! config('services.textbee.enabled')) {
            Log::info('SMS delivery skipped (TextBee disabled)', [
                'driver' => 'textbee',
            ]);

            return true;
        }

        $payload = [
            'recipients' => [$recipient],
            'message' => $message,
        ];

        $deviceId = config('services.textbee.device_id');

        if (filled($deviceId)) {
            $payload['deviceId'] = $deviceId;
        }

        $response = Http::timeout((int) config('services.textbee.timeout', 10))
            ->retry((int) config('services.textbee.retries', 2), 100, throw: false)
            ->withHeaders([
                'x-api-key' => config('services.textbee.api_key'),
            ])
            ->post(
                (string) config('services.textbee.endpoint', 'https://api.textbee.dev/api/v1/gateway/send-sms'),
                $payload,
            );

        return $response->successful();
    }
}
