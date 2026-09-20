<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Notifications\NotifyPatientAccount;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AuditEvent;
use App\Models\AppointmentRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

class ResolveConflictingAppointmentRequests
{
    public const DEFAULT_REJECTION_REASON = 'This time is no longer available because another appointment request was accepted for this time.';

    public function __construct(
        private readonly EvaluateAppointmentRequestPreferences $evaluatePreferences,
        private readonly CreateAuditLog $createAuditLog,
        private readonly NotifyPatientAccount $notifyPatientAccount,
    ) {}

    public function handle(
        AppointmentRequest $acceptedRequest,
        User $reviewer,
        CarbonInterface $scheduledAt,
        int $durationMinutes,
    ): void {
        $acceptedStartsAt = $scheduledAt->copy()->setTimezone(config('app.timezone'));
        $acceptedEndsAt = $acceptedStartsAt->copy()->addMinutes($durationMinutes);

        $pendingRequests = AppointmentRequest::query()
            ->actionablePending()
            ->whereKeyNot($acceptedRequest->getKey())
            ->with(['appointmentType', 'appointment.status', 'user'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($pendingRequests as $pendingRequest) {
            try {
                if ($pendingRequest->scheduled_at === null
                    || ! $pendingRequest->isPending()
                    || ! $this->hasConflictingPreference($pendingRequest, $acceptedStartsAt, $acceptedEndsAt)) {
                    continue;
                }

                $duration = (int) ($pendingRequest->provisional_duration_minutes
                    ?? $pendingRequest->appointmentType?->duration_minutes
                    ?? 30);

                $ignoreAppointment = $pendingRequest->isRebooking()
                    && $pendingRequest->appointment_id !== $acceptedRequest->appointment_id
                    ? $pendingRequest->appointment
                    : null;

                $decisions = $this->evaluatePreferences->handle(
                    request: $pendingRequest,
                    durationMinutes: $duration,
                    ignoreAppointment: $ignoreAppointment,
                );

                if (collect($decisions)->contains(fn (array $decision): bool => $decision['available'])) {
                    continue;
                }
            } catch (InvalidFormatException|\TypeError) {
                // Keep malformed legacy requests pending for staff review.
                continue;
            }

            $pendingRequest->update([
                'status' => AppointmentRequestStatus::Rejected,
                'resolved_by_user_id' => $reviewer->id,
                'resolved_at' => now(),
                'rejection_reason' => self::DEFAULT_REJECTION_REASON,
            ]);

            $this->createAuditLog->handle(
                subject: $pendingRequest,
                action: AuditEvent::AppointmentRequestRejected,
                metadata: [
                    'patient_id' => $pendingRequest->patient_id,
                    'reason_provided' => true,
                    'automated' => true,
                    'conflict_reason' => 'accepted_time_unavailable',
                    'accepted_request_id' => $acceptedRequest->id,
                    'accepted_scheduled_at' => $scheduledAt->toIso8601String(),
                ],
                actorId: $reviewer->id,
            );

            $this->notifyPatientAccount->appointmentRequestDeclined($pendingRequest);
        }
    }

    private function hasConflictingPreference(
        AppointmentRequest $request,
        CarbonInterface $acceptedStartsAt,
        CarbonInterface $acceptedEndsAt,
    ): bool {
        $duration = (int) ($request->provisional_duration_minutes
            ?? $request->appointmentType?->duration_minutes
            ?? 30);

        return collect($request->getAllTimePreferences())
            ->contains(function (mixed $preference) use ($duration, $acceptedStartsAt, $acceptedEndsAt): bool {
                if (! is_string($preference) || blank($preference)) {
                    return false;
                }

                $preferenceStartsAt = Carbon::parse($preference)->setTimezone(config('app.timezone'));
                $preferenceEndsAt = $preferenceStartsAt->copy()->addMinutes($duration);

                return $preferenceStartsAt->lt($acceptedEndsAt)
                    && $preferenceEndsAt->gt($acceptedStartsAt);
            });
    }
}
