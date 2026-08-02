<?php

namespace App\Actions\Appointments;

use App\Models\AppointmentRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SubmitAppointmentRequest
{
    public function __construct(
        protected BuildScheduleBlocks $buildBlocks,
        protected BuildAppointmentRequestIdentitySnapshot $buildSnapshot,
    ) {}

    /**
     * @param  array{first_name?: string, middle_name?: ?string, last_name?: string, date_of_birth?: string}|null  $identity
     */
    public function handle(
        User $account,
        CarbonInterface $scheduledAt,
        string $reasonForVisit,
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

        // Validate the slot is available
        $blocks = $this->buildBlocks->forDate($scheduledAt);

        $provisionalDuration = config('patient_accounts.appointment_requests.hold_duration_minutes', 30);
        $slotEnd = $scheduledAt->copy()->addMinutes($provisionalDuration);

        $conflicts = $blocks->filter(fn (ScheduleBlock $block) => $block->overlaps($scheduledAt, $slotEnd));

        if ($conflicts->isNotEmpty()) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The requested time slot is no longer available.'],
            ]);
        }

        RateLimiter::hit($rateLimitKey, 3600); // 1 hour window

        // Build identity snapshot (null for linked accounts)
        $snapshot = $this->buildSnapshot->handle($account, $identity);

        return DB::transaction(function () use ($account, $scheduledAt, $reasonForVisit, $provisionalDuration, $snapshot) {
            $patientId = $account->patient?->id;

            return AppointmentRequest::create([
                'user_id' => $account->id,
                'patient_id' => $patientId,
                'scheduled_at' => $scheduledAt,
                'provisional_duration_minutes' => $provisionalDuration,
                'encrypted_reason_for_visit' => $reasonForVisit,
                'encrypted_identity_snapshot' => $snapshot,
                'status' => 'pending',
                'expires_at' => now()->addHours(config('patient_accounts.appointment_requests.expiry_hours', 24)),
            ]);
        });
    }
}
