<?php

namespace App\Filament\Resources\Appointments\Support;

use Illuminate\Support\Carbon;

class AppointmentTime
{
    public static function combine(string $date, string $time): Carbon
    {
        return Carbon::parse($date)->setTimeFromTimeString($time);
    }
}
