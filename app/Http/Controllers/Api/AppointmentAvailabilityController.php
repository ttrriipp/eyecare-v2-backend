<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointments\ListAvailableAppointmentSlots;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AppointmentAvailabilityRequest;
use App\Models\Appointment;
use App\Models\User;
use App\Models\VisitReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AppointmentAvailabilityController extends Controller
{
    public function __invoke(
        AppointmentAvailabilityRequest $request,
        ListAvailableAppointmentSlots $listAvailableAppointmentSlots,
    ): JsonResponse {
        $visitReason = VisitReason::query()->findOrFail($request->validated('visit_reason_id'));
        $optometrist = $request->filled('optometrist_id')
            ? User::query()->findOrFail($request->validated('optometrist_id'))
            : null;
        $appointment = $request->filled('appointment_id')
            ? Appointment::query()->findOrFail($request->validated('appointment_id'))
            : null;
        $date = Carbon::createFromFormat('Y-m-d', $request->validated('date'), config('app.timezone'));

        $slots = $listAvailableAppointmentSlots->handle(
            date: $date,
            visitReason: $visitReason,
            optometrist: $optometrist,
            ignoreAppointment: $appointment,
        );

        return response()->json([
            'data' => [
                'date' => $date->toDateString(),
                'timezone' => config('app.timezone'),
                'interval_minutes' => (int) config('appointments.clinic_hours.slot_interval_minutes', 15),
                'visit_reason_id' => $visitReason->id,
                'visit_duration_minutes' => $visitReason->duration_minutes,
                'optometrist_id' => $optometrist?->id,
                'appointment_id' => $appointment?->id,
                'day_status' => $this->dayStatus($date),
                'generated_at' => now(config('app.timezone'))->toIso8601String(),
                'slots' => collect($slots)
                    ->map(fn ($slot): array => [
                        'starts_at' => $slot->startsAt->toIso8601String(),
                        'ends_at' => $slot->endsAt->toIso8601String(),
                        'available' => $slot->available,
                        'reason' => $slot->available ? null : $slot->reason,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    private function dayStatus(Carbon $date): string
    {
        return in_array(
            $date->dayOfWeek,
            config('appointments.clinic_hours.closed_weekdays', [0]),
            true,
        ) ? 'closed' : 'open';
    }
}
