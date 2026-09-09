<?php

namespace App\Jobs;

use App\Actions\Sms\ProcessSmsNotification;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public SmsNotification $sms) {}

    public function handle(ProcessSmsNotification $action): void
    {
        $action->handle($this->sms);
    }

    public function failed(?Throwable $exception): void
    {
        $queuedStatusId = NotificationStatus::query()
            ->where('name', 'queued')
            ->value('id');
        $failedStatusId = NotificationStatus::query()
            ->where('name', 'failed')
            ->value('id');

        if ($queuedStatusId === null || $failedStatusId === null) {
            return;
        }

        SmsNotification::query()
            ->whereKey($this->sms->getKey())
            ->where('notification_status_id', $queuedStatusId)
            ->update([
                'notification_status_id' => $failedStatusId,
                'failure_reason' => 'SMS delivery job failed.',
            ]);
    }
}
