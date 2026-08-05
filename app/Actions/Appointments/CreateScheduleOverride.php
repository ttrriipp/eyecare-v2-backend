<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\ScheduleOverrideType;
use App\Models\ScheduleOverride;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateScheduleOverride
{
    public function handle(
        ScheduleOverrideType $type,
        string $overrideDate,
        ?int $userId = null,
        ?string $startTime = null,
        ?string $endTime = null,
        ?string $reason = null,
    ): ScheduleOverride {
        if (Carbon::parse($overrideDate)->isPast() && ! Carbon::parse($overrideDate)->isToday()) {
            throw ValidationException::withMessages([
                'override_date' => ['The override date must be today or in the future.'],
            ]);
        }

        $optometrist = null;

        if ($userId !== null) {
            $optometrist = User::query()->findOrFail($userId);
        }

        match ($type) {
            ScheduleOverrideType::Closed => $this->assertClinicWide($userId, $startTime, $endTime, 'A clinic closure'),
            ScheduleOverrideType::EarlyClose => $this->assertEarlyClose($userId, $startTime, $endTime),
            ScheduleOverrideType::ProviderAbsence => $this->assertProviderAbsence($optometrist, $startTime, $endTime),
        };

        $exists = ScheduleOverride::query()
            ->where('override_date', $overrideDate)
            ->where('type', $type->value)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'override_date' => ['An override of this type already exists for this date.'],
            ]);
        }

        return DB::transaction(function () use ($type, $overrideDate, $userId, $startTime, $endTime, $reason): ScheduleOverride {
            $override = ScheduleOverride::query()->create([
                'user_id' => $userId,
                'override_date' => $overrideDate,
                'type' => $type->value,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'reason' => $reason,
            ]);

            app(CreateAuditLog::class)->handle(
                subject: $override,
                action: AuditEvent::ScheduleOverrideCreated,
                metadata: [
                    'type' => $type->value,
                    'override_date' => $overrideDate,
                    'user_id' => $userId,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'reason' => $reason,
                ],
            );

            return $override;
        });
    }

    private function assertClinicWide(?int $userId, ?string $startTime, ?string $endTime, string $label): void
    {
        if ($userId !== null || $startTime !== null || $endTime !== null) {
            throw ValidationException::withMessages([
                'type' => ["{$label} applies to the whole clinic and cannot have a provider or time range."],
            ]);
        }
    }

    private function assertEarlyClose(?int $userId, ?string $startTime, ?string $endTime): void
    {
        if ($userId !== null || $endTime !== null) {
            throw ValidationException::withMessages([
                'type' => ['Early closing applies to the whole clinic and only needs a closing time.'],
            ]);
        }

        if ($startTime === null) {
            throw ValidationException::withMessages([
                'start_time' => ['The early closing time is required.'],
            ]);
        }
    }

    private function assertProviderAbsence(?User $optometrist, ?string $startTime, ?string $endTime): void
    {
        if ($optometrist === null) {
            throw ValidationException::withMessages([
                'user_id' => ['An optometrist is required for a provider absence.'],
            ]);
        }

        if (! $optometrist->is_optometrist) {
            throw ValidationException::withMessages([
                'user_id' => ['Only optometrists can have a provider absence.'],
            ]);
        }

        if (($startTime === null) !== ($endTime === null)) {
            throw ValidationException::withMessages([
                'start_time' => ['Provide both a start and end time for a partial-day absence, or leave both blank for a full day.'],
            ]);
        }

        if ($startTime !== null && $endTime !== null && $startTime >= $endTime) {
            throw ValidationException::withMessages([
                'start_time' => ['Start time must be before end time.'],
            ]);
        }
    }
}
