<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointments\AppointmentAvailabilityDecision;
use App\Actions\Appointments\ClinicSchedule;
use App\Actions\Appointments\ListAppointmentRequestAvailabilitySlots;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AppointmentRequestAvailabilityRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AppointmentRequestAvailabilityController extends Controller
{
    public function __invoke(
        AppointmentRequestAvailabilityRequest $request,
        ListAppointmentRequestAvailabilitySlots $listSlots,
    ): JsonResponse {
        $date = Carbon::createFromFormat(
            'Y-m-d',
            $request->validated('date'),
            config('app.timezone'),
        );
        $schedule = ClinicSchedule::forDate($date);
        $slotDurationMinutes = (int) config(
            'patient_accounts.appointment_requests.hold_duration_minutes',
            30,
        );

        return response()->json([
            'data' => [
                'date' => $date->toDateString(),
                'timezone' => config('app.timezone'),
                'interval_minutes' => $slotDurationMinutes,
                'slot_duration_minutes' => $slotDurationMinutes,
                'day_status' => $schedule->isClosed ? 'closed' : 'open',
                'generated_at' => now(config('app.timezone'))->toIso8601String(),
                'slots' => collect($listSlots->handle($date, $slotDurationMinutes))
                    ->map(fn (AppointmentAvailabilityDecision $slot): array => [
                        'starts_at' => $slot->startsAt->toIso8601String(),
                        'ends_at' => $slot->endsAt->toIso8601String(),
                        'available' => $slot->available,
                        'reason' => $slot->reason,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }
}
