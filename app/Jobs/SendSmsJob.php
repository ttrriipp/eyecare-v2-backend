<?php

namespace App\Jobs;

use App\Actions\Sms\ProcessSmsNotification;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendSmsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int|array $backoff = [30, 120, 300];

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public SmsNotification $sms) {}

    public function handle(ProcessSmsNotification $action): void
    {
        $sms = $this->sms->fresh();

        if ($sms === null) {
            return;
        }

        $action->handle($sms, throwOnProviderFailure: true);
    }

    public function uniqueId(): string
    {
        return (string) $this->sms->getKey();
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('sms-notification:'.$this->sms->getKey()))
                ->releaseAfter(30)
                ->expireAfter(120),
        ];
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

        $sms = SmsNotification::query()
            ->whereKey($this->sms->getKey())
            ->where('notification_status_id', $queuedStatusId)
            ->first();

        if ($sms === null) {
            return;
        }

        $outcomeUnknown = $sms->provider_name === 'textbee' && $sms->delivery_state === 'sending';

        $updates = [
            'notification_status_id' => $failedStatusId,
            'failure_reason' => $outcomeUnknown
                ? 'TextBee send outcome is unknown after a job failure. Verify with the provider before retrying.'
                : ($exception?->getMessage() ?? 'SMS delivery job failed.'),
        ];

        if ($sms->provider_name === 'textbee') {
            $updates += [
                'delivery_state' => $outcomeUnknown ? 'unknown' : 'failed',
                'provider_status' => $outcomeUnknown ? 'unknown' : $sms->provider_status,
                'provider_status_updated_at' => $outcomeUnknown ? now() : $sms->provider_status_updated_at,
            ];
        }

        $sms->update($updates);
    }
}
