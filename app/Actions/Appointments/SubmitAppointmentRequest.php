<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
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
    ) {}

    /**
     * @param  list<string>|null  $alternativeScheduledTimes
     * @param  array{phone?: string, email?: ?string, first_name?: string, middle_name?: ?string, last_name?: string, date_of_birth?: string, gender?: string, occupation?: string, address?: string}|null  $identity
     */
    public function handle(
        User $account,
        AppointmentType $appointmentType,
        CarbonInterface $scheduledAt,
        string $reasonForVisit,
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
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->count();

        $maxActive = config('patient_accounts.appointment_requests.max_active_per_account', 2);

        if ($activeRequests >= $maxActive) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['You have reached the maximum number of active appointment requests.'],
            ]);
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

        return DB::transaction(function () use (
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

            return $request;
        });
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
