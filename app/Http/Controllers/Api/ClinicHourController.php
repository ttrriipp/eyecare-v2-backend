<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClinicHourResource;
use App\Models\ClinicHour;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClinicHourController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $hours = ClinicHour::query()
            ->orderBy('weekday')
            ->get()
            ->keyBy('weekday');

        $weeklyHours = collect(range(0, 6))
            ->map(fn (int $weekday): ClinicHour => $hours->get($weekday) ?? new ClinicHour([
                'weekday' => $weekday,
                'open_time' => config('appointments.clinic_hours.opens_at', '09:00'),
                'close_time' => config('appointments.clinic_hours.closes_at', '17:00'),
                'enabled' => true,
            ]));

        return ClinicHourResource::collection($weeklyHours);
    }
}
