<?php

namespace App\Console\Commands;

use App\Jobs\SendSmsJob;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use Illuminate\Console\Command;

class ProcessPendingSmsCommand extends Command
{
    protected $signature = 'sms:process {--limit=50 : Maximum number of SMS to dispatch}';

    protected $description = 'Dispatch queued SMS notifications to the job queue';

    public function handle(): int
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

        $pending->each(fn (SmsNotification $sms) => SendSmsJob::dispatch($sms));

        $this->info("Dispatched {$pending->count()} SMS job(s) to the queue.");

        return self::SUCCESS;
    }
}
