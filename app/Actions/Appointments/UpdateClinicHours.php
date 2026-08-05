<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\ClinicHour;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateClinicHours
{
    public function handle(
        int $weekday,
        bool $enabled,
        string $openTime,
        string $closeTime,
    ): ClinicHour {
        if ($enabled && $openTime >= $closeTime) {
            throw ValidationException::withMessages([
                'open_time' => ['Opening time must be before closing time.'],
            ]);
        }

        return DB::transaction(function () use ($weekday, $enabled, $openTime, $closeTime): ClinicHour {
            $clinicHour = ClinicHour::query()->updateOrCreate(
                ['weekday' => $weekday],
                [
                    'enabled' => $enabled,
                    'open_time' => $openTime,
                    'close_time' => $closeTime,
                ],
            );

            app(CreateAuditLog::class)->handle(
                subject: $clinicHour,
                action: AuditEvent::ClinicHoursUpdated,
                metadata: [
                    'weekday' => $weekday,
                    'enabled' => $enabled,
                    'open_time' => $openTime,
                    'close_time' => $closeTime,
                ],
            );

            return $clinicHour;
        });
    }
}
