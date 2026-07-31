<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\AppointmentRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class BuildScheduleBlocks
{
    /**
     * @return Collection<int, ScheduleBlock>
     */
    public function forDate(CarbonInterface $date, ?int $excludeAppointmentId = null, ?int $excludeRequestId = null): Collection
    {
        $blocks = collect();
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();

        // Blocks from active appointments
        $appointments = Appointment::query()
            ->where('scheduled_at', '>=', $dayStart)
            ->where('scheduled_at', '<=', $dayEnd)
            ->whereIn('appointment_status_id', function ($query) {
                $query->select('id')
                    ->from('appointment_statuses')
                    ->whereIn('name', ['scheduled', 'checked_in']);
            })
            ->get();

        foreach ($appointments as $appointment) {
            if ($excludeAppointmentId !== null && $appointment->id === $excludeAppointmentId) {
                continue;
            }

            $blocks->push(new ScheduleBlock(
                startsAt: $appointment->scheduled_at,
                endsAt: $appointment->scheduled_at->copy()->addMinutes($appointment->duration_minutes ?? 30),
                source: 'appointment',
                sourceId: $appointment->id,
                optometristId: $appointment->optometrist_id,
            ));
        }

        // Blocks from unexpired pending request holds
        $requests = AppointmentRequest::query()
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->where('scheduled_at', '>=', $dayStart)
            ->where('scheduled_at', '<=', $dayEnd)
            ->get();

        foreach ($requests as $request) {
            if ($excludeRequestId !== null && $request->id === $excludeRequestId) {
                continue;
            }

            $blocks->push(new ScheduleBlock(
                startsAt: $request->scheduled_at,
                endsAt: $request->scheduled_at->copy()->addMinutes($request->provisional_duration_minutes),
                source: 'request',
                sourceId: $request->id,
            ));
        }

        return $blocks;
    }
}
