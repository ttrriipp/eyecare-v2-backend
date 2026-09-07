<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AppointmentRescheduleRequestStatus;
use App\Enums\AuditEvent;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ExpireAppointmentRescheduleRequests
{
    public function __construct(private readonly CreateAuditLog $createAuditLog) {}

    public function handle(): int
    {
        return Cache::lock('expire_appointment_reschedule_requests', 60)->block(5, function (): int {
            $requestIds = AppointmentRescheduleRequest::query()
                ->where('status', AppointmentRescheduleRequestStatus::Pending->value)
                ->orderBy('id')
                ->pluck('id');
            $expired = 0;

            foreach ($requestIds as $requestId) {
                if ($this->expireRequest((int) $requestId)) {
                    $expired++;
                }
            }

            return $expired;
        });
    }

    private function expireRequest(int $requestId): bool
    {
        return DB::transaction(function () use ($requestId): bool {
            $requestSnapshot = AppointmentRescheduleRequest::query()->find($requestId);

            if ($requestSnapshot === null) {
                return false;
            }

            $appointment = Appointment::query()
                ->with('status')
                ->lockForUpdate()
                ->find($requestSnapshot->appointment_id);

            if ($appointment === null) {
                return false;
            }

            $request = AppointmentRescheduleRequest::query()
                ->lockForUpdate()
                ->find($requestId);

            if ($request === null || $request->status !== AppointmentRescheduleRequestStatus::Pending) {
                return false;
            }

            $request->setRelation('appointment', $appointment);

            if ($request->isPending()) {
                return false;
            }

            $request->update([
                'status' => AppointmentRescheduleRequestStatus::Expired,
                'resolved_at' => now(),
            ]);

            $this->createAuditLog->handle(
                subject: $request,
                action: AuditEvent::AppointmentRescheduleRequestExpired,
                metadata: [
                    'appointment_id' => $request->appointment_id,
                    'patient_id' => $request->patient_id,
                    'request_number' => $request->request_number,
                ],
            );

            return true;
        }, attempts: 3);
    }
}
