<?php

namespace App\Console\Commands;

use App\Actions\Appointments\ExpireAppointmentRequests as ExpireAction;
use App\Actions\Appointments\ExpireAppointmentRescheduleRequests;
use Illuminate\Console\Command;

class ExpireAppointmentRequestsCommand extends Command
{
    protected $signature = 'appointments:expire-requests';

    protected $description = 'Expire pending appointment requests that have passed their expiry time';

    public function handle(
        ExpireAction $expire,
        ExpireAppointmentRescheduleRequests $expireRescheduleRequests,
    ): int {
        $expired = $expire->handle();
        $expiredRescheduleRequests = $expireRescheduleRequests->handle();

        $this->info("Expired {$expired} appointment request(s) and {$expiredRescheduleRequests} reschedule request(s).");

        return self::SUCCESS;
    }
}
