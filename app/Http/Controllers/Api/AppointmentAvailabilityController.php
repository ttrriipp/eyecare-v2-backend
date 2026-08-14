<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointments\ClinicSchedule;
use App\Actions\Appointments\ListAvailableAppointmentSlots;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AppointmentAvailabilityRequest;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AppointmentAvailabilityController extends Controller
{
    public function __invoke(
        AppointmentAvailabilityRequest $request,
        ListAvailableAppointmentSlots $listAvailableAppointmentSlots,
    ): JsonResponse {
        $optometrist = $request->filled('optometrist_id')
            ? User::query()->findOrFail($request->validated('optometrist_id'))
            : null;
        $appointment = $request->filled('appointment_id')
            ? Appointment::query()
                ->where('patient_id', $request->user()?->patient?->id)
                ->findOrFail($request->validated('appointment_id'))
            : null;

        $appointmentTypeId = $appointment?->appointment_type_id
            ?? $request->validated('appointment_type_id');
        $appointmentType = AppointmentType::query()->findOrFail($appointmentTypeId);

        $date = Carbon::createFromFormat('Y-m-d', $request->validated('date'), config('app.timezone'));
        $schedule = ClinicSchedule::forDate($date);

        $slots = $listAvailableAppointmentSlots->handle(
            date: $date,
            durationMinutes: $appointmentType->duration_minutes,
            optometrist: $optometrist,
            ignoreAppointment: $appointment,
        );

        return response()->json([
            'data' => [
                'date' => $date->toDateString(),
                'timezone' => config('app.timezone'),
                'interval_minutes' => $schedule->slotIntervalMinutes,
                'appointment_type_id' => $appointmentType->id,
                'visit_duration_minutes' => $appointmentType->duration_minutes,
                'optometrist_id' => $optometrist?->id,
                'appointment_id' => $appointment?->id,
                'day_status' => $schedule->isClosed ? 'closed' : 'open',
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
}
