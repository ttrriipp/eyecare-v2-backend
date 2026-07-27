<?php

namespace App\Actions\Appointments;

use App\Models\ClinicHour;
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

        return ClinicHour::query()->updateOrCreate(
            ['weekday' => $weekday],
            [
                'enabled' => $enabled,
                'open_time' => $openTime,
                'close_time' => $closeTime,
            ],
        );
    }
}
