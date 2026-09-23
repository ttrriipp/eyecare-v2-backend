<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TextBeeService implements ReportsSmsOutcomeUncertainty, SmsGateway
{
    private ?string $failureReason = null;

    private bool $retryableFailure = false;

    private bool $outcomeMayBeUnknown = false;

    private ?string $providerReference = null;

    private ?string $providerStatus = null;

    private ?string $providerMessageId = null;

    public function isEnabled(): bool
    {
        return config('services.textbee.enabled') === true;
    }

    public function send(string $recipient, string $message): bool
    {
        $this->failureReason = null;
        $this->retryableFailure = false;
        $this->outcomeMayBeUnknown = false;
        $this->providerReference = null;
        $this->providerStatus = null;
        $this->providerMessageId = null;

        if (! $this->isEnabled()) {
            $this->failureReason = 'SMS provider is disabled.';
            Log::info('SMS delivery skipped (TextBee disabled)', [
                'driver' => 'textbee',
            ]);

            return false;
        }

        $payload = [
            'recipients' => [$recipient],
            'message' => $message,
        ];

        $deviceId = config('services.textbee.device_id');

        if (filled($deviceId)) {
            $payload['deviceId'] = $deviceId;
        }

        try {
            $response = Http::connectTimeout((int) config('services.textbee.connect_timeout', 5))
                ->timeout((int) config('services.textbee.timeout', 10))
                ->withHeaders([
                    'x-api-key' => config('services.textbee.api_key'),
                ])
                ->post(
                    (string) config('services.textbee.endpoint', 'https://api.textbee.dev/api/v1/gateway/send-sms'),
                    $payload,
                );
        } catch (ConnectionException) {
            $this->outcomeMayBeUnknown = true;
            $this->providerStatus = 'unknown';
            $this->failureReason = 'TextBee connection failed; delivery outcome may be unknown. Verify with the provider before retrying.';

            return false;
        }

        if (! $response->successful()) {
            if ($response->status() === 429) {
                $this->providerStatus = 'quota_exceeded';
                $this->failureReason = 'TextBee plan limit reached; retry after the daily, monthly, or per-batch quota resets.';

                return false;
            }

            $this->outcomeMayBeUnknown = $response->serverError();
            $this->providerStatus = $this->outcomeMayBeUnknown ? 'unknown' : null;
            $this->failureReason = $this->outcomeMayBeUnknown
                ? "TextBee returned HTTP {$response->status()}; delivery outcome may be unknown. Verify with the provider before retrying."
                : "TextBee returned HTTP {$response->status()}.";

            return false;
        }

        $payload = $response->json();
        $data = is_array($payload) && is_array($payload['data'] ?? null)
            ? $payload['data']
            : (is_array($payload) ? $payload : []);
        $success = $data['success'] ?? (is_array($payload) ? ($payload['success'] ?? null) : null);
        $successCount = (int) ($data['successCount'] ?? 0);
        $failureCount = (int) ($data['failureCount'] ?? 0);

        $this->providerReference = isset($data['smsBatchId']) ? (string) $data['smsBatchId'] : null;
        $this->providerMessageId = isset($data['smsId']) ? (string) $data['smsId'] : null;

        if (isset($data['status'])) {
            $this->providerStatus = strtolower((string) $data['status']);
        } elseif ($this->providerReference !== null) {
            $this->providerStatus = 'queued';
        } elseif ($successCount > 0) {
            $this->providerStatus = 'accepted';
        }

        if ($success === false || ($failureCount > 0 && $successCount === 0)) {
            $this->failureReason = 'TextBee rejected the SMS request.';

            return false;
        }

        if ($success !== true && $successCount === 0) {
            $this->outcomeMayBeUnknown = true;
            $this->providerStatus = 'unknown';
            $this->failureReason = 'TextBee returned an unexpected response.';

            return false;
        }

        $this->providerStatus ??= 'accepted';

        return true;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    public function isRetryableFailure(): bool
    {
        return $this->retryableFailure;
    }

    public function providerReference(): ?string
    {
        return $this->providerReference;
    }

    public function providerStatus(): ?string
    {
        return $this->providerStatus;
    }

    public function outcomeMayBeUnknown(): bool
    {
        return $this->outcomeMayBeUnknown;
    }

    public function providerMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function messagesForBatch(string $batchId): ?array
    {
        if (! $this->isEnabled() || blank(config('services.textbee.api_key'))) {
            return null;
        }

        try {
            $response = Http::connectTimeout((int) config('services.textbee.connect_timeout', 5))
                ->timeout((int) config('services.textbee.timeout', 10))
                ->withHeaders(['x-api-key' => config('services.textbee.api_key')])
                ->get((string) config('services.textbee.messages_endpoint'), [
                    'smsBatchId' => $batchId,
                    'direction' => 'sent',
                    'limit' => 50,
                ]);
        } catch (ConnectionException) {
            Log::warning('TextBee delivery status lookup failed to connect', ['sms_batch_id' => $batchId]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('TextBee delivery status lookup returned an error', [
                'sms_batch_id' => $batchId,
                'http_status' => $response->status(),
            ]);

            return null;
        }

        $messages = $response->json('data');

        return is_array($messages) ? array_values(array_filter($messages, 'is_array')) : [];
    }
}
