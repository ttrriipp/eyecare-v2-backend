<?php

namespace App\Actions\Appointments;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ListAppointmentRequestAvailabilitySlots
{
    public function __construct(
        private readonly BuildScheduleBlocks $buildScheduleBlocks,
    ) {}

    /**
     * @return array<int, AppointmentAvailabilityDecision>
     */
    public function handle(
        CarbonInterface $date,
        int $durationMinutes,
    ): array {
        $schedule = ClinicSchedule::forDate($date);

        if ($schedule->isClosed) {
            return [];
        }

        $slotInterval = $schedule->slotIntervalMinutes;

        $slot = Carbon::parse(
            $date->format('Y-m-d').' '.$schedule->openTime,
            config('app.timezone'),
        );
        $closingTime = Carbon::parse(
            $date->format('Y-m-d').' '.$schedule->closeTime,
            config('app.timezone'),
        );

        // Get only confirmed appointment blocks (not request holds)
        $blocks = $this->buildScheduleBlocks->forDate($date);

        // Filter to only appointment blocks (exclude request holds)
        $appointmentBlocks = $blocks->filter(
            fn (ScheduleBlock $block): bool => $block->source === 'appointment',
        );

        $slots = [];

        while ($slot->copy()->addMinutes($durationMinutes)->lte($closingTime)) {
            $endsAt = $slot->copy()->addMinutes($durationMinutes);

            $slots[] = $this->evaluateSlot(
                startsAt: $slot,
                endsAt: $endsAt,
                blocks: $appointmentBlocks,
            );

            $slot->addMinutes($slotInterval);
        }

        return $slots;
    }

    /**
     * @param  Collection<int, ScheduleBlock>  $blocks
     */
    private function evaluateSlot(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        Collection $blocks,
    ): AppointmentAvailabilityDecision {
        if (! $startsAt->isFuture()) {
            return AppointmentAvailabilityDecision::unavailable($startsAt, $endsAt, 'elapsed');
        }

        // Check if the interval overlaps any confirmed appointment
        if ($blocks->contains(fn (ScheduleBlock $block): bool => $block->overlaps($startsAt, $endsAt))) {
            return AppointmentAvailabilityDecision::unavailable($startsAt, $endsAt, 'capacity_reached');
        }

        return AppointmentAvailabilityDecision::available($startsAt, $endsAt);
    }
}
