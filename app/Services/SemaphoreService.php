<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SemaphoreService implements SmsGateway
{
    private ?string $failureReason = null;

    private bool $retryableFailure = false;

    private ?string $providerReference = null;

    private ?string $providerStatus = null;

    public function isEnabled(): bool
    {
        return config('services.semaphore.enabled') === true;
    }

    public function send(string $recipient, string $message): bool
    {
        $this->failureReason = null;
        $this->retryableFailure = false;
        $this->providerReference = null;
        $this->providerStatus = null;

        if (! $this->isEnabled()) {
            $this->failureReason = 'SMS provider is disabled.';
            Log::info('SMS delivery skipped (Semaphore disabled)', [
                'driver' => 'semaphore',
            ]);

            return false;
        }

        try {
            $response = Http::connectTimeout((int) config('services.semaphore.connect_timeout', 5))
                ->timeout((int) config('services.semaphore.timeout', 10))
                ->post((string) config('services.semaphore.endpoint', 'https://api.semaphore.co/api/v4/messages'), [
                    'apikey' => config('services.semaphore.api_key'),
                    'number' => $recipient,
                    'message' => $message,
                    'sendername' => config('services.semaphore.sender_name'),
                ]);
        } catch (ConnectionException) {
            $this->failureReason = 'Semaphore connection failed; delivery outcome may be unknown. Verify with the provider before retrying.';

            return false;
        }

        if (! $response->successful()) {
            if ($response->status() === 429) {
                $this->retryableFailure = true;
                $this->providerStatus = 'rate_limited';
                $this->failureReason = 'Semaphore rate-limited the SMS request.';

                return false;
            }

            $this->failureReason = $response->serverError()
                ? "Semaphore returned HTTP {$response->status()}; delivery outcome may be unknown. Verify with the provider before retrying."
                : "Semaphore returned HTTP {$response->status()}.";

            return false;
        }

        $messages = $response->json();

        if (! is_array($messages)) {
            $this->failureReason = 'Semaphore returned an unexpected response.';

            return false;
        }

        $messages = array_is_list($messages) ? $messages : [$messages];
        $messageResult = $messages[0] ?? null;

        if (! is_array($messageResult) || ! isset($messageResult['status'])) {
            $this->failureReason = 'Semaphore returned an unexpected response.';

            return false;
        }

        $status = strtolower((string) $messageResult['status']);
        $this->providerReference = isset($messageResult['message_id'])
            ? (string) $messageResult['message_id']
            : null;
        $this->providerStatus = $status;

        if (in_array($status, ['failed', 'error', 'rejected', 'refunded'], true)) {
            $this->failureReason = "Semaphore rejected the SMS request with status '{$status}'.";

            return false;
        }

        if (! in_array($status, ['queued', 'pending', 'sent', 'success'], true)) {
            $this->failureReason = "Semaphore returned an unknown SMS status '{$status}'.";

            return false;
        }

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
}
