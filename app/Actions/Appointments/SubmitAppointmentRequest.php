<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Notifications\NotifyAdminUsers;
use App\Enums\AppointmentRequestKind;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Enums\AuditEvent;
use App\Exceptions\ActiveAppointmentRequestLimitReached;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SubmitAppointmentRequest
{
    public function __construct(
        protected BuildScheduleBlocks $buildBlocks,
        protected BuildAppointmentRequestIdentitySnapshot $buildSnapshot,
        protected ListAppointmentRequestAvailabilitySlots $listSlots,
        protected CreateAuditLog $createAuditLog,
        protected NotifyAdminUsers $notifyAdminUsers,
    ) {}

    /**
     * @param  list<string>|null  $alternativeScheduledTimes
     * @param  array{phone?: string, email?: ?string, first_name?: string, middle_name?: ?string, last_name?: string, date_of_birth?: string, gender?: string, occupation?: string, address?: string}|null  $identity
     */
    public function handle(
        User $account,
        AppointmentType $appointmentType,
        CarbonInterface $scheduledAt,
        ?string $reasonForVisit,
        ?array $alternativeScheduledTimes = null,
        ?string $referringSource = null,
        ?array $identity = null,
    ): AppointmentRequest {
        // Rate limit check
        $rateLimitKey = 'appointment_request:'.$account->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['Too many appointment requests. Please try again later.'],
            ]);
        }

        // Check active request limit
        $activeRequests = AppointmentRequest::where('user_id', $account->id)
            ->where('status', AppointmentRequestStatus::Pending)
            ->where('expires_at', '>', now())
            ->count();

        $maxActive = config('patient_accounts.appointment_requests.max_active_per_account', 2);

        if ($activeRequests >= $maxActive) {
            throw new ActiveAppointmentRequestLimitReached($maxActive);
        }

        $provisionalDuration = $appointmentType->duration_minutes;

        // Collect all time preferences (primary + alternatives)
        $allTimes = collect([$scheduledAt->toIso8601String()]);
        if ($alternativeScheduledTimes !== null) {
            $allTimes = $allTimes->merge($alternativeScheduledTimes);
        }

        // Validate all time preferences are currently available
        foreach ($allTimes as $timeString) {
            $time = Carbon::parse($timeString, config('app.timezone'));
            $this->validateTimeAvailability($time, $provisionalDuration);
        }

        RateLimiter::hit($rateLimitKey, 3600); // 1 hour window

        // Build identity snapshot (null for linked accounts)
        $snapshot = $this->buildSnapshot->handle($account, $identity);

        // Calculate expiry: latest submitted preference
        $expiresAt = $this->calculateExpiry($allTimes);

        $request = DB::transaction(function () use (
            $account,
            $appointmentType,
            $scheduledAt,
            $alternativeScheduledTimes,
            $referringSource,
            $reasonForVisit,
            $provisionalDuration,
            $snapshot,
            $expiresAt,
        ) {
            $patientId = $account->patient?->id;

            $request = AppointmentRequest::create([
                'user_id' => $account->id,
                'request_type' => AppointmentRequestKind::New,
                'patient_id' => $patientId,
                'appointment_type_id' => $appointmentType->id,
                'scheduled_at' => $scheduledAt,
                'alternative_scheduled_times' => $alternativeScheduledTimes,
                'provisional_duration_minutes' => $provisionalDuration,
                'encrypted_reason_for_visit' => $reasonForVisit,
                'encrypted_referring_source' => $referringSource,
                'encrypted_identity_snapshot' => $snapshot,
                'status' => 'pending',
                'expires_at' => $expiresAt,
            ]);

            $this->createAuditLog->handle(
                subject: $request,
                action: AuditEvent::AppointmentRequestSubmitted,
                metadata: [
                    'account_id' => $account->id,
                    'patient_id' => $patientId,
                    'appointment_type_id' => $appointmentType->id,
                    'scheduled_at' => $scheduledAt->toIso8601String(),
                ],
                actorId: $account->id,
            );

            $this->createSmsNotification($account, $scheduledAt);

            return $request;
        });

        $this->notifyAdminUsers->appointmentRequestSubmitted($request);

        return $request;
    }

    /**
     * Submit a patient request to move an existing scheduled appointment.
     *
     * @param  list<string>|null  $alternativeScheduledTimes
     */
    public function handleRebooking(
        User $account,
        Appointment $appointment,
        CarbonInterface $scheduledAt,
        ?array $alternativeScheduledTimes = null,
        ?string $reasonForVisit = null,
    ): AppointmentRequest {
        $rateLimitKey = 'appointment_request:'.$account->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['Too many appointment requests. Please try again later.'],
            ]);
        }

        $patient = $account->patient;
        if ($patient === null || $appointment->patient_id !== $patient->id) {
            abort(404);
        }

        $appointmentType = $appointment->appointmentType;
        if ($appointmentType === null) {
            throw ValidationException::withMessages([
                'appointment_id' => ['The appointment type could not be resolved.'],
            ]);
        }

        $durationMinutes = (int) ($appointment->duration_minutes ?? $appointmentType?->duration_minutes ?? 30);
        $allTimes = collect([$scheduledAt->toIso8601String()]);
        if ($alternativeScheduledTimes !== null) {
            $allTimes = $allTimes->merge($alternativeScheduledTimes);
        }

        $this->validateRebookingTimes($allTimes, $appointment, $durationMinutes);
        $expiresAt = $this->calculateExpiry($allTimes);

        RateLimiter::hit($rateLimitKey, 3600);

        $request = DB::transaction(function () use (
            $account,
            $appointment,
            $scheduledAt,
            $alternativeScheduledTimes,
            $reasonForVisit,
            $expiresAt,
            $allTimes,
        ): AppointmentRequest {
            User::query()->lockForUpdate()->findOrFail($account->id);

            $lockedAppointment = Appointment::query()
                ->with(['appointmentType', 'status'])
                ->lockForUpdate()
                ->findOrFail($appointment->id);

            $patient = $account->patient;
            if ($patient === null || $lockedAppointment->patient_id !== $patient->id) {
                abort(404);
            }

            if ($lockedAppointment->status?->name !== AppointmentStatusName::Scheduled->value) {
                throw ValidationException::withMessages([
                    'appointment_id' => ['Only scheduled appointments can be requested for rebooking.'],
                ]);
            }

            if (! $lockedAppointment->scheduled_at->isFuture()) {
                throw ValidationException::withMessages([
                    'appointment_id' => ['Only future appointments can be requested for rebooking.'],
                ]);
            }

            $maxActive = config('patient_accounts.appointment_requests.max_active_per_account', 2);
            $activeRequests = AppointmentRequest::query()
                ->where('user_id', $account->id)
                ->where('status', AppointmentRequestStatus::Pending)
                ->where('expires_at', '>', now())
                ->count();

            if ($activeRequests >= $maxActive) {
                throw new ActiveAppointmentRequestLimitReached($maxActive);
            }

            $hasPendingRebooking = AppointmentRequest::query()
                ->where('appointment_id', $lockedAppointment->id)
                ->where('status', AppointmentRequestStatus::Pending)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->exists();

            if ($hasPendingRebooking) {
                throw ValidationException::withMessages([
                    'appointment_id' => ['This appointment already has a pending rebooking request.'],
                ]);
            }

            $lockedDurationMinutes = (int) ($lockedAppointment->duration_minutes
                ?? $lockedAppointment->appointmentType?->duration_minutes
                ?? 30);

            $this->validateRebookingTimes($allTimes, $lockedAppointment, $lockedDurationMinutes);

            $request = AppointmentRequest::create([
                'user_id' => $account->id,
                'request_type' => AppointmentRequestKind::Reschedule,
                'patient_id' => $patient->id,
                'appointment_type_id' => $lockedAppointment->appointment_type_id,
                'appointment_id' => $lockedAppointment->id,
                'original_scheduled_at' => $lockedAppointment->scheduled_at,
                'selected_scheduled_at' => null,
                'scheduled_at' => $scheduledAt,
                'alternative_scheduled_times' => $alternativeScheduledTimes,
                'provisional_duration_minutes' => $lockedDurationMinutes,
                'encrypted_reason_for_visit' => $reasonForVisit,
                'encrypted_referring_source' => null,
                'encrypted_identity_snapshot' => null,
                'status' => AppointmentRequestStatus::Pending,
                'expires_at' => $expiresAt,
            ]);

            $this->createAuditLog->handle(
                subject: $request,
                action: AuditEvent::AppointmentRequestSubmitted,
                metadata: [
                    'account_id' => $account->id,
                    'patient_id' => $patient->id,
                    'appointment_id' => $lockedAppointment->id,
                    'appointment_type_id' => $lockedAppointment->appointment_type_id,
                    'request_type' => AppointmentRequestKind::Reschedule->value,
                    'scheduled_at' => $scheduledAt->toIso8601String(),
                ],
                actorId: $account->id,
            );

            $this->createSmsNotification($account, $scheduledAt, true, $lockedAppointment);

            return $request;
        }, attempts: 3);

        $this->notifyAdminUsers->appointmentRequestSubmitted($request);

        return $request;
    }

    private function createSmsNotification(
        User $account,
        CarbonInterface $scheduledAt,
        bool $isRebooking = false,
        ?Appointment $appointment = null,
    ): void {
        if (blank($account->phone)) {
            return;
        }

        $message = $isRebooking && $appointment !== null
            ? "We received your request to move appointment {$appointment->appointment_number} to {$scheduledAt->format('M j, Y g:i A')}. We'll confirm it soon."
            : "We received your appointment request for {$scheduledAt->format('M j, Y g:i A')}. We'll confirm it soon.";

        SmsNotification::query()->create([
            'appointment_id' => null,
            'notification_status_id' => NotificationStatus::query()->where('name', 'queued')->value('id'),
            'event' => 'appointment_request_submitted',
            'recipient' => $account->phone,
            'message' => $message,
        ]);
    }

    private function validateTimeAvailability(
        CarbonInterface $time,
        int $durationMinutes,
    ): void {
        $date = $time->toDateString();
        $slots = $this->listSlots->handle(
            date: Carbon::parse($date, config('app.timezone')),
            durationMinutes: $durationMinutes,
        );

        $isAvailable = collect($slots)->contains(
            fn (AppointmentAvailabilityDecision $slot): bool => $slot->available && $slot->startsAt->equalTo($time),
        );

        if (! $isAvailable) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The requested time slot is no longer available.'],
            ]);
        }
    }

    /**
     * @param  Collection<int, string>  $allTimes
     */
    private function validateRebookingTimes(
        Collection $allTimes,
        Appointment $appointment,
        int $durationMinutes,
    ): void {
        $currentStart = $appointment->scheduled_at;
        $currentEnd = $currentStart->copy()->addMinutes($appointment->duration_minutes ?? 30);

        foreach ($allTimes as $index => $timeString) {
            $time = Carbon::parse($timeString, config('app.timezone'));
            $endsAt = $time->copy()->addMinutes($durationMinutes);

            if ($time->equalTo($currentStart) || ($time->lt($currentEnd) && $endsAt->gt($currentStart))) {
                throw ValidationException::withMessages([
                    'scheduled_at' => ['The requested time must differ from the current appointment time.'],
                ]);
            }

            $this->validateTimeAvailabilityForRebooking(
                time: $time,
                durationMinutes: $durationMinutes,
                appointmentId: $appointment->id,
                attribute: $index === 0 ? 'scheduled_at' : 'alternative_scheduled_times',
            );
        }
    }

    private function validateTimeAvailabilityForRebooking(
        CarbonInterface $time,
        int $durationMinutes,
        int $appointmentId,
        string $attribute,
    ): void {
        $date = $time->toDateString();
        $slots = $this->listSlots->handle(
            date: Carbon::parse($date, config('app.timezone')),
            durationMinutes: $durationMinutes,
            excludeAppointmentId: $appointmentId,
        );

        $isAvailable = collect($slots)->contains(
            fn (AppointmentAvailabilityDecision $slot): bool => $slot->available && $slot->startsAt->equalTo($time),
        );

        if (! $isAvailable) {
            throw ValidationException::withMessages([
                $attribute => ['The requested time slot is no longer available.'],
            ]);
        }
    }

    /**
     * Calculate expiry: the latest submitted preference time.
     *
     * @param  Collection<int, string>  $allTimes
     */
    private function calculateExpiry(Collection $allTimes): CarbonInterface
    {
        return $allTimes
            ->map(fn (string $time): CarbonInterface => Carbon::parse($time, config('app.timezone')))
            ->sortByDesc(fn (CarbonInterface $date): int => $date->timestamp)
            ->first();
    }
}
