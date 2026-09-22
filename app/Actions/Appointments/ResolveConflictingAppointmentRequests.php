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
use Illuminate\Support\Collection;

class ResolveConflictingAppointmentRequests
{
    public const DEFAULT_REJECTION_REASON = 'This time is no longer available because another appointment request was accepted for this time.';

    public const DIRECT_BOOKING_REJECTION_REASON = 'This time is no longer available because another appointment was scheduled for this time.';

    public function __construct(
        private readonly EvaluateAppointmentRequestPreferences $evaluatePreferences,
        private readonly CreateAuditLog $createAuditLog,
        private readonly NotifyPatientAccount $notifyPatientAccount,
    ) {}

    public function handle(
        ?AppointmentRequest $acceptedRequest,
        User $reviewer,
        CarbonInterface $scheduledAt,
        int $durationMinutes,
        ?string $rejectionReason = null,
        ?string $conflictReason = null,
    ): void {
        $rejectionReason ??= $acceptedRequest === null
            ? self::DIRECT_BOOKING_REJECTION_REASON
            : self::DEFAULT_REJECTION_REASON;
        $conflictReason ??= $acceptedRequest === null
            ? 'direct_appointment_scheduled'
            : 'accepted_time_unavailable';

        $acceptedStartsAt = $scheduledAt->copy()->setTimezone(config('app.timezone'));
        $acceptedEndsAt = $acceptedStartsAt->copy()->addMinutes($durationMinutes);

        $pendingRequestsQuery = AppointmentRequest::query()
            ->actionablePending()
            ->with(['appointmentType', 'appointment.status', 'user'])
            ->orderBy('id');

        if ($acceptedRequest !== null) {
            $pendingRequestsQuery->whereKeyNot($acceptedRequest->getKey());
        }

        $pendingRequests = $pendingRequestsQuery
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
                    && ($acceptedRequest === null
                        || $pendingRequest->appointment_id !== $acceptedRequest->appointment_id)
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
                'rejection_reason' => $rejectionReason,
            ]);

            $metadata = [
                'patient_id' => $pendingRequest->patient_id,
                'reason_provided' => true,
                'automated' => true,
                'conflict_reason' => $conflictReason,
            ];

            if ($acceptedRequest !== null) {
                $metadata['accepted_request_id'] = $acceptedRequest->id;
                $metadata['accepted_scheduled_at'] = $scheduledAt->toIso8601String();
            } else {
                $metadata['appointment_scheduled_at'] = $scheduledAt->toIso8601String();
            }

            $this->createAuditLog->handle(
                subject: $pendingRequest,
                action: AuditEvent::AppointmentRequestRejected,
                metadata: $metadata,
                actorId: $reviewer->id,
            );

            $this->notifyPatientAccount->appointmentRequestDeclined($pendingRequest);
        }
    }

    /**
     * Find actionable requests that include a preference overlapping a slot.
     *
     * This is intentionally a warning-only preview. The transactional handler
     * re-evaluates each preference after the appointment is saved and rejects a
     * request only when none of its submitted times remain available.
     *
     * @return Collection<int, AppointmentRequest>
     */
    public function findPotentialConflicts(
        CarbonInterface $scheduledAt,
        int $durationMinutes,
    ): Collection {
        if ($durationMinutes < 5) {
            return collect();
        }

        $startsAt = $scheduledAt->copy()->setTimezone(config('app.timezone'));
        $endsAt = $startsAt->copy()->addMinutes($durationMinutes);

        return AppointmentRequest::query()
            ->actionablePending()
            ->with(['appointmentType', 'appointment.status', 'patient', 'user'])
            ->orderBy('id')
            ->get()
            ->filter(function (AppointmentRequest $request) use ($startsAt, $endsAt): bool {
                try {
                    return $request->scheduled_at !== null
                        && $request->isPending()
                        && $this->hasConflictingPreference($request, $startsAt, $endsAt);
                } catch (InvalidFormatException|\TypeError) {
                    return false;
                }
            })
            ->values();
    }

    /**
     * Build the confirmation copy for a set of requests that overlap a slot.
     *
     * @param  Collection<int, AppointmentRequest>  $requests
     */
    public function getPotentialConflictDescription(Collection $requests): string
    {
        $count = $requests->count();
        $requestLabel = $count === 1 ? 'request includes' : 'requests include';
        $requestList = $requests
            ->map(function (AppointmentRequest $request): string {
                $patientName = $request->patient?->full_name
                    ?? $request->getSnapshotDisplayName()
                    ?? 'Unlinked patient';

                return "{$request->request_number} — {$patientName}";
            })
            ->implode('; ');

        return "The following {$count} pending appointment {$requestLabel} this time: {$requestList}. Continuing will automatically reject any affected request that has no remaining available preference because this time is no longer available.";
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
