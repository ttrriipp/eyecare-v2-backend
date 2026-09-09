<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AppointmentStatusName;
use App\Enums\AuditEvent;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UpdateAppointmentRequestSchedule
{
    public function __construct(
        private readonly ListAppointmentRequestAvailabilitySlots $listSlots,
        private readonly LockAppointmentScheduleDate $lockScheduleDate,
        private readonly CreateAuditLog $createAuditLog,
    ) {}

    /**
     * Update the submitted schedule preferences on an owned pending request.
     *
     * The request row is locked before linked-appointment and schedule-date
     * locks are acquired, matching the staff acceptance lock order.
     *
     * @param  list<string>|null  $alternativeScheduledTimes
     */
    public function handle(
        AppointmentRequest $appointmentRequest,
        User $account,
        CarbonInterface $scheduledAt,
        ?array $alternativeScheduledTimes = null,
    ): AppointmentRequest {
        if ($appointmentRequest->user_id !== $account->id) {
            abort(404);
        }

        $preferences = collect([$scheduledAt])
            ->merge($alternativeScheduledTimes ?? [])
            ->map(fn (CarbonInterface|string $time): CarbonInterface => $time instanceof CarbonInterface
                ? $time->copy()
                : Carbon::parse($time, config('app.timezone')))
            ->values();

        return DB::transaction(function () use (
            $appointmentRequest,
            $account,
            $preferences,
            $alternativeScheduledTimes,
        ): AppointmentRequest {
            $lockedRequest = AppointmentRequest::query()
                ->with(['appointmentType', 'appointment.status'])
                ->lockForUpdate()
                ->findOrFail($appointmentRequest->id);

            if ($lockedRequest->user_id !== $account->id) {
                abort(404);
            }

            if (! $lockedRequest->isPending()) {
                $this->throwRequestNotReschedulable();
            }

            $linkedAppointment = null;
            $excludeAppointmentId = null;
            $durationMinutes = (int) ($lockedRequest->provisional_duration_minutes ?? 30);

            if ($lockedRequest->isRebooking()) {
                $linkedAppointment = $this->lockedLinkedAppointment($lockedRequest);
                $excludeAppointmentId = $linkedAppointment->id;
                $durationMinutes = (int) ($linkedAppointment->duration_minutes
                    ?? $linkedAppointment->appointmentType?->duration_minutes
                    ?? 30);

                if ($lockedRequest->original_scheduled_at === null
                    || ! $lockedRequest->original_scheduled_at->equalTo($linkedAppointment->scheduled_at)) {
                    $this->throwRequestNotReschedulable();
                }
            }

            if ($durationMinutes < 5 || $durationMinutes > 240 || $durationMinutes % 5 !== 0) {
                $this->throwRequestNotReschedulable();
            }

            $this->lockScheduleDates($preferences, $linkedAppointment);

            foreach ($preferences as $index => $time) {
                if ($linkedAppointment !== null && $time->equalTo($linkedAppointment->scheduled_at)) {
                    $this->throwSameAppointmentTime($index);
                }

                $this->validateTimeAvailability(
                    time: $time,
                    durationMinutes: $durationMinutes,
                    excludeAppointmentId: $excludeAppointmentId,
                    attribute: $index === 0
                        ? 'scheduled_at'
                        : sprintf('alternative_scheduled_times.%d', $index - 1),
                );
            }

            $previousScheduledAt = $lockedRequest->scheduled_at->copy();
            $previousAlternativeTimes = $lockedRequest->alternative_scheduled_times;
            $expiresAt = $preferences
                ->sortByDesc(fn (CarbonInterface $time): int => $time->getTimestamp())
                ->first();

            $lockedRequest->update([
                'scheduled_at' => $preferences->first(),
                'alternative_scheduled_times' => $alternativeScheduledTimes,
                'expires_at' => $expiresAt,
            ]);

            $this->createAuditLog->handle(
                subject: $lockedRequest,
                action: AuditEvent::AppointmentRequestScheduleUpdated,
                metadata: [
                    'account_id' => $account->id,
                    'patient_id' => $lockedRequest->patient_id,
                    'appointment_id' => $lockedRequest->appointment_id,
                    'request_type' => $lockedRequest->request_type?->value,
                    'from_scheduled_at' => $previousScheduledAt->toIso8601String(),
                    'to_scheduled_at' => $preferences->first()->toIso8601String(),
                    'from_alternative_count' => count($previousAlternativeTimes ?? []),
                    'to_alternative_count' => count($alternativeScheduledTimes ?? []),
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
                actorId: $account->id,
            );

            return $lockedRequest->fresh(['appointmentType', 'appointment.status']);
        }, attempts: 3);
    }

    private function lockedLinkedAppointment(AppointmentRequest $request): Appointment
    {
        if ($request->appointment_id === null || $request->patient_id === null) {
            $this->throwRequestNotReschedulable();
        }

        $appointment = Appointment::query()
            ->with(['appointmentType', 'status'])
            ->lockForUpdate()
            ->find($request->appointment_id);

        if ($appointment === null
            || $appointment->patient_id !== $request->patient_id
            || $appointment->status?->name !== AppointmentStatusName::Scheduled->value
            || ! $appointment->scheduled_at->isFuture()) {
            $this->throwRequestNotReschedulable();
        }

        return $appointment;
    }

    private function lockScheduleDates(Collection $preferences, ?Appointment $linkedAppointment): void
    {
        $dates = $preferences
            ->map(fn (CarbonInterface $time): string => $time
                ->copy()
                ->setTimezone(config('app.timezone'))
                ->toDateString());

        if ($linkedAppointment !== null) {
            $dates->push($linkedAppointment->scheduled_at
                ->copy()
                ->setTimezone(config('app.timezone'))
                ->toDateString());
        }

        $dates
            ->unique()
            ->sort()
            ->each(fn (string $date): mixed => $this->lockScheduleDate->handle($date));
    }

    private function validateTimeAvailability(
        CarbonInterface $time,
        int $durationMinutes,
        ?int $excludeAppointmentId,
        string $attribute,
    ): void {
        $slots = $this->listSlots->handle(
            date: Carbon::parse($time->toDateString(), config('app.timezone')),
            durationMinutes: $durationMinutes,
            excludeAppointmentId: $excludeAppointmentId,
        );

        $isAvailable = collect($slots)->contains(
            fn (AppointmentAvailabilityDecision $slot): bool => $slot->available && $slot->startsAt->equalTo($time),
        );

        if (! $isAvailable) {
            $this->throwSlotUnavailable($attribute);
        }
    }

    private function throwSameAppointmentTime(int $preferenceIndex): never
    {
        $attribute = $preferenceIndex === 0
            ? 'scheduled_at'
            : sprintf('alternative_scheduled_times.%d', $preferenceIndex - 1);

        throw new HttpResponseException(response()->json([
            'message' => 'The requested time must differ from the current appointment time.',
            'errors' => [
                $attribute => ['The requested time must differ from the current appointment time.'],
            ],
        ], 422));
    }

    private function throwSlotUnavailable(string $attribute): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'This time slot is no longer available. Please choose another time.',
            'code' => 'SLOT_UNAVAILABLE',
            'errors' => [
                $attribute => ['This time slot is no longer available. Please choose another time.'],
            ],
        ], 422));
    }

    private function throwRequestNotReschedulable(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'This appointment request can no longer be rescheduled.',
            'code' => 'REQUEST_NOT_RESCHEDULABLE',
            'errors' => [
                'request' => ['Only pending appointment requests can be rescheduled.'],
            ],
        ], 422));
    }
}
