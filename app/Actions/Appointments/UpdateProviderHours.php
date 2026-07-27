<?php

namespace App\Actions\Appointments;

use App\Models\ProviderHour;
use App\Models\User;
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

        if (! $user->is_optometrist) {
            throw ValidationException::withMessages([
                'user_id' => ['Only optometrists can have provider hours.'],
            ]);
        }

        if ($enabled && $startTime >= $endTime) {
            throw ValidationException::withMessages([
                'start_time' => ['Start time must be before end time.'],
            ]);
        }

        return ProviderHour::query()->updateOrCreate(
            ['user_id' => $userId, 'weekday' => $weekday],
            [
                'enabled' => $enabled,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ],
        );
    }
}
