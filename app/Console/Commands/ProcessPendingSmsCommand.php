<?php

namespace App\Console\Commands;

use App\Actions\Sms\ProcessSmsNotification;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use Illuminate\Console\Command;

class ProcessPendingSmsCommand extends Command
{
    protected $signature = 'sms:process {--limit=50 : Maximum number of SMS to process}';

    protected $description = 'Process queued SMS notifications via Semaphore';

    public function handle(ProcessSmsNotification $action): int
    {
        $queuedStatus = NotificationStatus::query()->where('name', 'queued')->firstOrFail();

        $pending = SmsNotification::query()
            ->where('notification_status_id', $queuedStatus->id)
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No queued SMS notifications.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($pending as $sms) {
            $statusBefore = $sms->status->name;
            $action->handle($sms);
            $sms->refresh();

            if ($sms->status->name === 'sent') {
                $sent++;
            } else {
                $failed++;
            }
        }

        $this->info("Processed {$pending->count()} SMS: {$sent} sent, {$failed} failed.");

        return self::SUCCESS;
    }
}
