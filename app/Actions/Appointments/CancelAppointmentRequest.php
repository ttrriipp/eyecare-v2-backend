<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Notifications\NotifyAdminUsers;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AuditEvent;
use App\Models\AppointmentRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelAppointmentRequest
{
    public function __construct(
        private readonly CreateAuditLog $createAuditLog,
        private readonly NotifyAdminUsers $notifyAdminUsers,
    ) {}

    public function handle(AppointmentRequest $request, User $account, ?string $reasonDetails = null): AppointmentRequest
    {
        if ($request->user_id !== $account->id) {
            abort(404);
        }

        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => ['Only pending appointment requests can be cancelled.'],
            ]);
        }

        if (blank($reasonDetails)) {
            throw ValidationException::withMessages([
                'reason_details' => ['Please provide a reason for cancelling the appointment request.'],
            ]);
        }

        $request = DB::transaction(function () use ($request, $account, $reasonDetails): AppointmentRequest {
            $request = AppointmentRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $request->isPending()) {
                throw ValidationException::withMessages([
                    'request' => ['Only pending appointment requests can be cancelled.'],
                ]);
            }

            if ($request->scheduled_at->copy()->setTimezone(config('app.timezone'))->isToday()) {
                throw ValidationException::withMessages([
                    'request' => ['Same-day cancellations are not allowed. Please contact the clinic for assistance.'],
                ]);
            }

            $request->update([
                'status' => AppointmentRequestStatus::Cancelled,
                'encrypted_cancellation_reason' => $reasonDetails,
            ]);

            $this->createAuditLog->handle(
                subject: $request,
                action: AuditEvent::AppointmentRequestCancelled,
                metadata: [
                    'patient_id' => $request->patient_id,
                    'account_id' => $account->id,
                ],
                actorId: $account->id,
            );

            return $request->fresh();
        });

        $this->notifyAdminUsers->appointmentRequestCancelled($request);

        return $request;
    }
}
