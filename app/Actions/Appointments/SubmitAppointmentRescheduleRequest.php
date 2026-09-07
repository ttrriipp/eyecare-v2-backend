<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AppointmentRescheduleRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Enums\AuditEvent;
use App\Exceptions\AppointmentRescheduleRequestStateException;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitAppointmentRescheduleRequest
{
    public function __construct(
        private readonly EvaluateAppointmentAvailability $evaluateAppointmentAvailability,
        private readonly LockAppointmentScheduleDate $lockAppointmentScheduleDate,
        private readonly CreateAuditLog $createAuditLog,
    ) {}

    /**
     * @param  list<CarbonInterface|string>  $alternativeScheduledTimes
     */
    public function handle(
        User $account,
        Appointment $appointment,
        CarbonInterface $requestedScheduledAt,
        array $alternativeScheduledTimes = [],
        ?string $reasonDetails = null,
    ): AppointmentRescheduleRequest {
        if (count($alternativeScheduledTimes) > 2) {
            throw ValidationException::withMessages([
                'alternative_scheduled_times' => ['You may submit at most two alternative times.'],
            ]);
        }

        $requestedScheduledAt = $this->normalizeTime($requestedScheduledAt, 'requested_scheduled_at');
        $alternativeScheduledTimes = array_map(
            fn (CarbonInterface|string $time): CarbonInterface => $this->normalizeTime($time, 'alternative_scheduled_times'),
            array_values($alternativeScheduledTimes),
        );
        $reasonDetails = filled($reasonDetails) ? trim($reasonDetails) : null;

        if ($reasonDetails !== null && mb_strlen($reasonDetails) > 1000) {
            throw ValidationException::withMessages([
                'reason_details' => ['The reason details may not exceed 1000 characters.'],
            ]);
        }

        $preferences = collect([$requestedScheduledAt, ...$alternativeScheduledTimes]);
        $this->validatePreferenceShape($preferences);

        return DB::transaction(function () use (
            $account,
            $appointment,
            $preferences,
            $reasonDetails,
        ): AppointmentRescheduleRequest {
            $lockedAppointment = Appointment::query()
                ->with(['status', 'optometrist'])
                ->lockForUpdate()
                ->findOrFail($appointment->id);
            $patient = $account->patient()->first();

            if ($patient === null || $lockedAppointment->patient_id !== $patient->id) {
                throw ValidationException::withMessages([
                    'appointment' => ['This appointment does not belong to the active patient account.'],
                ]);
            }

            if ($lockedAppointment->status?->name !== AppointmentStatusName::Scheduled->value
                || $lockedAppointment->scheduled_at === null
                || ! $lockedAppointment->scheduled_at->isFuture()) {
                throw AppointmentRescheduleRequestStateException::appointmentNotReschedulable();
            }

            $this->validateRequestedTimes($preferences, $lockedAppointment);
            $this->lockScheduleDates($lockedAppointment, $preferences);
            $this->expireStalePendingRequests($lockedAppointment);

            $this->validateAvailability($preferences, $lockedAppointment);

            $expiresAt = $this->calculateExpiry($lockedAppointment, $preferences);
            $request = AppointmentRescheduleRequest::query()->create([
                'appointment_id' => $lockedAppointment->id,
                'user_id' => $account->id,
                'patient_id' => $patient->id,
                'current_scheduled_at' => $lockedAppointment->scheduled_at,
                'requested_scheduled_at' => $preferences->first(),
                'alternative_scheduled_times' => $preferences
                    ->skip(1)
                    ->map(fn (CarbonInterface $time): string => $time->toIso8601String())
                    ->values()
                    ->all(),
                'encrypted_reason_details' => $reasonDetails,
                'status' => AppointmentRescheduleRequestStatus::Pending,
                'expires_at' => $expiresAt,
            ]);

            $this->createAuditLog->handle(
                subject: $request,
                action: AuditEvent::AppointmentRescheduleRequestSubmitted,
                metadata: [
                    'appointment_id' => $lockedAppointment->id,
                    'patient_id' => $patient->id,
                    'request_number' => $request->request_number,
                    'requested_scheduled_at' => $request->requested_scheduled_at->toIso8601String(),
                ],
                actorId: $account->id,
            );

            return $request->fresh();
        }, attempts: 3);
    }

    private function normalizeTime(CarbonInterface|string $time, string $field): CarbonInterface
    {
        try {
            return ($time instanceof CarbonInterface
                ? $time->copy()
                : Carbon::parse($time, config('app.timezone'))
            )->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => ['The time must be a valid date and time.'],
            ]);
        }
    }

    /**
     * @param  Collection<int, CarbonInterface>  $preferences
     */
    private function validatePreferenceShape(Collection $preferences): void
    {
        foreach ($preferences as $index => $preference) {
            if (! $preference->isFuture()) {
                throw ValidationException::withMessages([
                    $index === 0 ? 'requested_scheduled_at' : 'alternative_scheduled_times' => [
                        'All requested times must be in the future.',
                    ],
                ]);
            }

            foreach ($preferences->slice($index + 1) as $other) {
                if ($preference->equalTo($other)) {
                    throw ValidationException::withMessages([
                        'alternative_scheduled_times' => ['Requested times must be distinct.'],
                    ]);
                }
            }
        }
    }

    /**
     * @param  Collection<int, CarbonInterface>  $preferences
     */
    private function validateRequestedTimes(Collection $preferences, Appointment $appointment): void
    {
        foreach ($preferences as $index => $preference) {
            if ($preference->equalTo($appointment->scheduled_at)) {
                throw ValidationException::withMessages([
                    $index === 0 ? 'requested_scheduled_at' : 'alternative_scheduled_times' => [
                        'A reschedule time must differ from the current appointment time.',
                    ],
                ]);
            }
        }
    }

    private function expireStalePendingRequests(Appointment $appointment): void
    {
        $pendingRequests = AppointmentRescheduleRequest::query()
            ->where('appointment_id', $appointment->id)
            ->where('status', AppointmentRescheduleRequestStatus::Pending->value)
            ->lockForUpdate()
            ->get();

        foreach ($pendingRequests as $pendingRequest) {
            $pendingRequest->setRelation('appointment', $appointment);

            if ($pendingRequest->isPending()) {
                throw AppointmentRescheduleRequestStateException::alreadyPending();
            }

            $pendingRequest->update([
                'status' => AppointmentRescheduleRequestStatus::Expired,
                'resolved_at' => now(),
            ]);
        }
    }

    /**
     * @param  Collection<int, CarbonInterface>  $preferences
     */
    private function validateAvailability(Collection $preferences, Appointment $appointment): void
    {
        foreach ($preferences as $preference) {
            $decision = $this->evaluateAppointmentAvailability->handle(
                startsAt: $preference,
                durationMinutes: $appointment->duration_minutes,
                optometrist: $appointment->optometrist,
                ignoreAppointment: $appointment,
                enforceFuture: true,
                enforceGrid: true,
            );

            if (! $decision->available) {
                throw AppointmentRescheduleRequestStateException::slotUnavailable();
            }
        }
    }

    /**
     * @param  Collection<int, CarbonInterface>  $preferences
     */
    private function lockScheduleDates(Appointment $appointment, Collection $preferences): void
    {
        $dates = collect([$appointment->scheduled_at])
            ->concat($preferences)
            ->map(fn (CarbonInterface $time): string => $time->copy()->setTimezone(config('app.timezone'))->toDateString())
            ->unique()
            ->sort()
            ->values();

        foreach ($dates as $date) {
            $this->lockAppointmentScheduleDate->handle($date);
        }
    }

    /**
     * @param  Collection<int, CarbonInterface>  $preferences
     */
    private function calculateExpiry(Appointment $appointment, Collection $preferences): CarbonInterface
    {
        $latestPreference = $preferences
            ->sortByDesc(fn (CarbonInterface $time): int => $time->getTimestamp())
            ->first();

        return $appointment->scheduled_at->lte($latestPreference)
            ? $appointment->scheduled_at->copy()
            : $latestPreference->copy();
    }
}
