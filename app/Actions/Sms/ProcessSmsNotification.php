<?php

namespace App\Actions\Sms;

use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use App\Services\SmsGateway;
use App\Services\SmsMessageFormatter;

class ProcessSmsNotification
{
    public function __construct(private readonly SmsGateway $smsGateway) {}

    public function handle(SmsNotification $sms): void
    {
        $providerEnabled = $this->smsGateway->isEnabled();
        $message = SmsMessageFormatter::brand($sms->message);

        if ($message !== $sms->message) {
            $sms->update(['message' => $message]);
        }

        $success = $this->smsGateway->send($sms->recipient, $message);

        $statusName = $success ? 'sent' : 'failed';
        $status = NotificationStatus::query()->where('name', $statusName)->firstOrFail();

        $sms->update([
            'notification_status_id' => $status->id,
            'failure_reason' => $success
                ? null
                : ($providerEnabled
                    ? 'SMS provider returned a failure response.'
                    : 'SMS provider is disabled.'),
        ]);
    }
}
