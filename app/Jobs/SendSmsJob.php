<?php

namespace App\Jobs;

use App\Actions\Sms\ProcessSmsNotification;
use App\Models\SmsNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
}
