<?php

namespace App\Actions\Sms;

use App\Models\Appointment;
use App\Models\JobOrder;
use App\Models\NotificationStatus;
use App\Models\Patient;
use App\Models\SmsNotification;
use App\Services\SmsMessageFormatter;
use InvalidArgumentException;

class QueuePatientSms
{
    public function handle(
        ?Patient $patient,
        string $event,
        string $message,
        ?Appointment $appointment = null,
        ?JobOrder $jobOrder = null,
    ): ?SmsNotification {
        if ($patient === null || blank($patient->phone)) {
            return null;
        }

        if (($appointment === null) === ($jobOrder === null)) {
            throw new InvalidArgumentException('An SMS must reference exactly one appointment or job order.');
        }

        $queuedStatusId = NotificationStatus::query()
            ->where('name', 'queued')
            ->firstOrFail()
            ->id;

        $attributes = [
            'appointment_id' => $appointment?->id,
            'job_order_id' => $jobOrder?->id,
            'event' => $event,
            'recipient' => $patient->phone,
        ];

        return SmsNotification::query()->firstOrCreate($attributes, [
            'notification_status_id' => $queuedStatusId,
            'message' => SmsMessageFormatter::brand($message),
        ]);
    }
}
