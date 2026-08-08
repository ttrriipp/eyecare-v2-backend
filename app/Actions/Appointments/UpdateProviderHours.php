<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\ClinicHour;
use App\Models\ProviderHour;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProviderHours
{
    public function handle(
        int $userId,
        int $weekday,
        bool $enabled,
        string $startTime,
        string $endTime,
    ): ProviderHour {
        $user = User::query()->findOrFail($userId);

        if (! $user->isOptometrist()) {
            throw ValidationException::withMessages([
                'user_id' => ['Only optometrists can have provider hours.'],
            ]);
        }

        if ($enabled && $startTime >= $endTime) {
            throw ValidationException::withMessages([
                'start_time' => ['Start time must be before end time.'],
            ]);
        }

        if ($enabled) {
            $this->assertFitsWithinClinicHours($weekday, $startTime, $endTime);
        }

        return DB::transaction(function () use ($userId, $weekday, $enabled, $startTime, $endTime): ProviderHour {
            $providerHour = ProviderHour::query()->updateOrCreate(
                ['user_id' => $userId, 'weekday' => $weekday],
                [
                    'enabled' => $enabled,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ],
            );

            app(CreateAuditLog::class)->handle(
                subject: $providerHour,
                action: AuditEvent::ProviderHoursUpdated,
                metadata: [
                    'user_id' => $userId,
                    'weekday' => $weekday,
                    'enabled' => $enabled,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ],
            );

            return $providerHour;
        });
    }

    private function assertFitsWithinClinicHours(int $weekday, string $startTime, string $endTime): void
    {
        $clinicHour = ClinicHour::query()->where('weekday', $weekday)->first();

        if ($clinicHour !== null && ! $clinicHour->enabled) {
            throw ValidationException::withMessages([
                'start_time' => ['The clinic is closed on this day, so provider hours cannot be set.'],
            ]);
        }

        $clinicOpen = $clinicHour?->open_time?->format('H:i') ?? config('appointments.clinic_hours.opens_at', '09:00');
        $clinicClose = $clinicHour?->close_time?->format('H:i') ?? config('appointments.clinic_hours.closes_at', '17:00');

        if ($startTime < $clinicOpen || $endTime > $clinicClose) {
            throw ValidationException::withMessages([
                'start_time' => ["Provider hours must fit within clinic hours ({$clinicOpen}\u{2013}{$clinicClose})."],
            ]);
        }
    }
}
