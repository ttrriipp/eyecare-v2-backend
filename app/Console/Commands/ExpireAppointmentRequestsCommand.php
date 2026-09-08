<?php

namespace App\Console\Commands;

use App\Actions\Appointments\ExpireAppointmentRequests as ExpireAction;
use Illuminate\Console\Command;

class ExpireAppointmentRequestsCommand extends Command
{
    protected $signature = 'appointments:expire-requests';

    protected $description = 'Expire pending appointment requests that have passed their expiry time';

    public function handle(ExpireAction $expire): int
    {
        $expired = $expire->handle();

        $this->info("Expired {$expired} appointment request(s).");

        return self::SUCCESS;
    }
}
