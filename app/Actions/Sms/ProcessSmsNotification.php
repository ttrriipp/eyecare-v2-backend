<?php

namespace App\Actions\Sms;

use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use App\Services\SmsGateway;

class ProcessSmsNotification
{
    public function __construct(private readonly SmsGateway $smsGateway) {}

    public function handle(SmsNotification $sms): void
    {
        $success = $this->smsGateway->send($sms->recipient, $sms->message);

        $statusName = $success ? 'sent' : 'failed';
        $status = NotificationStatus::query()->where('name', $statusName)->firstOrFail();

        $sms->update([
            'notification_status_id' => $status->id,
            'failure_reason' => $success ? null : 'SMS provider returned a failure response.',
        ]);
    }
}
