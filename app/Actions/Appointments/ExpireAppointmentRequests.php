<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AuditEvent;
use App\Models\AppointmentRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ExpireAppointmentRequests
{
    public function __construct(private readonly CreateAuditLog $createAuditLog) {}

    public function handle(): int
    {
        $lockKey = 'expire_appointment_requests';
        $lockDuration = 60; // seconds

        return Cache::lock($lockKey, $lockDuration)->block(5, function (): int {
            return DB::transaction(function (): int {
                $requests = AppointmentRequest::query()
                    ->where('status', AppointmentRequestStatus::Pending)
                    ->where('expires_at', '<=', now())
                    ->lockForUpdate()
                    ->get();

                $requests->each(function (AppointmentRequest $request): void {
                    $request->update(['status' => AppointmentRequestStatus::Expired]);

                    $this->createAuditLog->handle(
                        subject: $request,
                        action: AuditEvent::AppointmentRequestExpired,
                        metadata: [
                            'patient_id' => $request->patient_id,
                            'account_id' => $request->user_id,
                        ],
                    );
                });

                return $requests->count();
            });
        });
    }
}
