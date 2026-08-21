<?php

namespace App\Filament\Resources\AppointmentRequests\Widgets;

use App\Filament\Resources\AppointmentRequests\Pages\ReviewAppointmentRequestSchedule;
use App\Models\Appointment;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\DateClickInfo;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;

class AppointmentRequestScheduleCalendar extends CalendarWidget
{
    public int $requestId;

    public int $durationMinutes = 30;

    public ?string $proposedStart = null;

    protected bool $dateClickEnabled = true;

    protected bool $eventClickEnabled = false;

    protected bool $eventDragEnabled = false;

    protected bool $useFilamentTimezone = true;

    protected CalendarViewType $calendarView = CalendarViewType::TimeGridWeek;

    public function getOptions(): array
    {
        return [
            'headerToolbar' => [
                'start' => 'prev,next today',
                'center' => 'title',
                'end' => 'timeGridWeek,timeGridDay',
            ],
            'buttonText' => [
                'today' => 'Today',
                'timeGridWeek' => 'Week',
                'timeGridDay' => 'Day',
            ],
            'height' => 'auto',
            'allDaySlot' => false,
            'slotMinTime' => config('appointments.clinic_hours.opens_at', '09:00').':00',
            'slotMaxTime' => config('appointments.clinic_hours.closes_at', '17:00').':00',
            'slotDuration' => '00:15:00',
            'slotHeight' => 36,
            'nowIndicator' => true,
        ];
    }

    protected function getEvents(FetchInfo $info): Builder|array
    {
        $appointments = Appointment::query()
            ->with(['patient', 'status', 'appointmentType', 'optometrist'])
            ->whereHas('status', fn (Builder $query): Builder => $query->whereIn('name', ['scheduled', 'checked_in']))
            ->whereDate('scheduled_at', '>=', $info->start)
            ->whereDate('scheduled_at', '<=', $info->end)
            ->get();

        $events = $appointments->all();

        if ($this->proposedStart !== null) {
            $start = Carbon::parse($this->proposedStart);

            $events[] = CalendarEvent::make()
                ->title('Proposed slot')
                ->start($start)
                ->end($start->copy()->addMinutes($this->durationMinutes))
                ->backgroundColor('#8b5cf6')
                ->textColor('#ffffff')
                ->classNames(['ec-preview'])
                ->editable(false);
        }

        return $events;
    }

    protected function onDateClick(DateClickInfo $info): void
    {
        $this->dispatch(
            'appointment-request-schedule-slot-selected',
            start: $info->date->toIso8601String(),
        )->to(ReviewAppointmentRequestSchedule::class);
    }

    #[On('appointment-request-calendar-focus')]
    public function focusDate(string $start): void
    {
        $this->proposedStart = $start;
        $this->setOption('date', Carbon::parse($start)->toIso8601String());
        $this->refreshRecords();
    }
}
