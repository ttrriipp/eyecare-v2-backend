<?php

namespace App\Actions\Appointments;

use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use Illuminate\Support\Facades\Cache;

class ExpireAppointmentRequests
{
    public function handle(): int
    {
        $lockKey = 'expire_appointment_requests';
        $lockDuration = 60; // seconds

        return Cache::lock($lockKey, $lockDuration)->block(5, function () {
            return AppointmentRequest::query()
                ->where('status', AppointmentRequestStatus::Pending)
                ->where('expires_at', '<=', now())
                ->update(['status' => AppointmentRequestStatus::Expired]);
        });
    }
}
