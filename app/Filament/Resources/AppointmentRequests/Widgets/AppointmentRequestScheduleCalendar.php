<?php

namespace App\Filament\Resources\AppointmentRequests\Widgets;

use App\Actions\Appointments\EvaluateAppointmentAvailability;
use App\Filament\Resources\AppointmentRequests\Pages\ReviewAppointmentRequestSchedule;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use App\Models\User;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\DateClickInfo;
use Guava\Calendar\ValueObjects\EventDropInfo;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;

class AppointmentRequestScheduleCalendar extends CalendarWidget
{
    public int $requestId;

    public int $durationMinutes = 30;

    public ?int $appointmentTypeId = null;

    public ?int $optometristId = null;

    public ?string $proposedStart = null;

    public ?bool $proposedSlotAvailable = null;

    protected bool $dateClickEnabled = true;

    protected bool $eventClickEnabled = false;

    protected bool $eventDragEnabled = true;

    protected bool $useFilamentTimezone = true;

    protected CalendarViewType $calendarView = CalendarViewType::TimeGridDay;

    public function getOptions(): array
    {
        $options = [
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
            'height' => 480,
            'allDaySlot' => false,
            'slotMinTime' => config('appointments.clinic_hours.opens_at', '09:00').':00',
            'slotMaxTime' => config('appointments.clinic_hours.closes_at', '17:00').':00',
            'slotDuration' => '00:15:00',
            'slotHeight' => 28,
            'slotLabelInterval' => '01:00:00',
            'nowIndicator' => true,
        ];

        if ($this->proposedStart !== null) {
            $options['date'] = Carbon::parse($this->proposedStart)->toIso8601String();
        }

        return $options;
    }

    protected function getEvents(FetchInfo $info): Builder|array
    {
        $appointments = Appointment::query()
            ->with(['patient', 'status', 'appointmentType', 'optometrist'])
            ->whereHas('status', fn (Builder $query): Builder => $query->whereIn('name', ['scheduled', 'checked_in']))
            ->whereDate('scheduled_at', '>=', $info->start)
            ->whereDate('scheduled_at', '<=', $info->end)
            ->get();

        $events = $appointments
            ->map(fn (Appointment $appointment): CalendarEvent => $this->appointmentEvent($appointment))
            ->all();

        if ($this->proposedStart !== null) {
            $start = Carbon::parse($this->proposedStart);
            $isUnavailable = $this->proposedSlotAvailable === false;
            $canDragProposal = $this->proposedSlotAvailable !== null;
            $request = AppointmentRequest::query()->find($this->requestId);

            if ($request !== null) {
                $events[] = CalendarEvent::make($request)
                    ->title($isUnavailable ? 'Unavailable slot' : 'Proposed slot')
                    ->start($start)
                    ->end($start->copy()->addMinutes($this->durationMinutes))
                    ->backgroundColor($isUnavailable ? '#dc2626' : '#8b5cf6')
                    ->textColor('#ffffff')
                    ->classNames($isUnavailable ? ['ec-preview', 'ec-preview-unavailable'] : ['ec-preview'])
                    ->styles([$canDragProposal ? 'cursor: grab' : 'cursor: not-allowed'])
                    ->extendedProp('kind', 'proposed_slot')
                    ->editable($canDragProposal)
                    ->durationEditable(false);
            }
        }

        return $events;
    }

    private function appointmentEvent(Appointment $appointment): CalendarEvent
    {
        $event = $appointment->toCalendarEvent()->editable(false);

        if ($this->optometristId === null || $appointment->optometrist_id === $this->optometristId) {
            return $event;
        }

        return $event
            ->backgroundColor('#94a3b8')
            ->textColor('#ffffff')
            ->classNames(['ec-context-appointment']);
    }

    protected function onEventDrop(EventDropInfo $info, Model $event): bool
    {
        if (! $event instanceof AppointmentRequest
            || $event->getKey() !== $this->requestId
            || $this->getRawCalendarContextData('event.extendedProps.kind') !== 'proposed_slot'
            || ! $event->isPending()
            || $this->appointmentTypeId === null
            || $this->durationMinutes < 5
            || $this->durationMinutes > 240
            || (! $event->isRebooking() && $this->durationMinutes % 5 !== 0)) {
            return false;
        }

        $appointmentType = AppointmentType::active()->find($this->appointmentTypeId);

        if ($appointmentType === null) {
            return false;
        }

        $optometrist = $this->optometristId === null
            ? null
            : User::query()->optometrists()->find($this->optometristId);

        if ($this->optometristId !== null && $optometrist === null) {
            return false;
        }

        $startsAt = $info->event->getStart()->copy()->setTimezone(config('app.timezone'));
        $reviewedAppointment = $event->isRebooking() ? $event->appointment : null;

        if ($reviewedAppointment?->scheduled_at?->equalTo($startsAt)) {
            return false;
        }

        $decision = app(EvaluateAppointmentAvailability::class)->handle(
            startsAt: $startsAt,
            durationMinutes: $this->durationMinutes,
            optometrist: $optometrist,
            ignoreAppointment: $reviewedAppointment,
            enforceFuture: true,
            enforceGrid: true,
        );

        if (! $decision->available) {
            return false;
        }

        $this->dispatch(
            'appointment-request-schedule-slot-selected',
            start: $startsAt->toIso8601String(),
        )->to(ReviewAppointmentRequestSchedule::class);

        return true;
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
