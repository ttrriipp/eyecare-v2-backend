<?php

namespace App\Actions\Sms;

use App\Exceptions\SmsDeliveryException;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use App\Services\ReportsSmsOutcomeUncertainty;
use App\Services\SmsGateway;
use App\Services\SmsMessageFormatter;
use Illuminate\Support\Facades\DB;

class ProcessSmsNotification
{
    public function __construct(private readonly SmsGateway $smsGateway) {}

    public function handle(SmsNotification $sms, bool $throwOnProviderFailure = false): void
    {
        $queuedStatus = NotificationStatus::query()->where('name', 'queued')->firstOrFail();

        if ($sms->notification_status_id !== $queuedStatus->id) {
            return;
        }

        $tracksTextBeeDelivery = $this->smsGateway instanceof ReportsSmsOutcomeUncertainty;

        if ($tracksTextBeeDelivery) {
            $claimed = SmsNotification::query()
                ->whereKey($sms->getKey())
                ->where('notification_status_id', $queuedStatus->id)
                ->where(fn ($query) => $query->whereNull('delivery_state')->orWhere('delivery_state', 'queued'))
                ->update([
                    'provider_name' => 'textbee',
                    'delivery_state' => 'sending',
                    'send_attempted_at' => now(),
                    'send_attempt_count' => DB::raw('send_attempt_count + 1'),
                ]);

            if ($claimed !== 1) {
                return;
            }

            $sms->refresh();
        }

        $providerEnabled = $this->smsGateway->isEnabled();
        $message = SmsMessageFormatter::brand($sms->message);

        if ($message !== $sms->message) {
            $sms->update(['message' => $message]);
        }

        $success = $this->smsGateway->send($sms->recipient, $message);
        $failureReason = $this->smsGateway->failureReason();
        $providerMetadata = [
            'provider_name' => config('services.sms.driver'),
            'provider_reference' => $this->smsGateway->providerReference(),
            'provider_status' => $this->smsGateway->providerStatus(),
            'provider_message_id' => $tracksTextBeeDelivery
                ? $this->smsGateway->providerMessageId()
                : null,
            'provider_accepted_at' => $success ? now() : null,
        ];

        if (! $success && $this->smsGateway instanceof ReportsSmsOutcomeUncertainty && $this->smsGateway->outcomeMayBeUnknown()) {
            $failedStatus = NotificationStatus::query()->where('name', 'failed')->firstOrFail();
            $failureReason = $failureReason ?? 'TextBee delivery outcome is unknown. Check provider status before retrying.';

            $sms->update([
                ...$providerMetadata,
                'notification_status_id' => $failedStatus->id,
                'delivery_state' => 'unknown',
                'provider_status' => 'unknown',
                'provider_status_updated_at' => now(),
                'failure_reason' => $failureReason,
            ]);

            return;
        }

        if (! $success && $throwOnProviderFailure && $providerEnabled && $this->smsGateway->isRetryableFailure()) {
            $updates = [
                ...$providerMetadata,
                'failure_reason' => $failureReason ?? 'SMS provider returned a failure response.',
            ];

            if ($tracksTextBeeDelivery) {
                $updates['delivery_state'] = 'queued';
                $updates['send_attempted_at'] = null;
            }

            $sms->update($updates);

            throw new SmsDeliveryException($failureReason ?? 'SMS provider returned a failure response.');
        }

        $statusName = $success ? 'sent' : 'failed';
        $status = NotificationStatus::query()->where('name', $statusName)->firstOrFail();

        $updates = [
            ...$providerMetadata,
            'notification_status_id' => $status->id,
            'failure_reason' => $success
                ? null
                : ($providerEnabled
                    ? ($failureReason ?? 'SMS provider returned a failure response.')
                    : 'SMS provider is disabled.'),
        ];

        if ($tracksTextBeeDelivery) {
            $updates += [
                'delivery_state' => $success ? 'accepted' : 'failed',
                'provider_status_updated_at' => now(),
                'provider_status_checked_at' => null,
                'provider_last_event_id' => null,
            ];
        }

        $sms->update($updates);
    }
}
