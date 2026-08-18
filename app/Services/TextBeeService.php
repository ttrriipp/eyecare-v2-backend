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
                'recipient' => $recipient,
                'message' => $message,
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

        $response = Http::withHeaders([
            'x-api-key' => config('services.textbee.api_key'),
        ])->post('https://api.textbee.dev/api/v1/gateway/send-sms', $payload);

        return $response->successful();
    }
}
