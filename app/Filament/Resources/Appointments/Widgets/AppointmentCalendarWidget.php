<?php

namespace App\Filament\Resources\Appointments\Widgets;

use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Models\Appointment;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\DateClickInfo;
use Guava\Calendar\ValueObjects\EventClickInfo;
use Guava\Calendar\ValueObjects\EventDropInfo;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AppointmentCalendarWidget extends CalendarWidget
{
    protected bool $eventClickEnabled = true;

    protected bool $eventDragEnabled = true;

    protected bool $dateClickEnabled = true;

    // Interpret calendar times in the Filament/app timezone (Asia/Manila) instead of
    // the raw browser locale. Without this, drag-and-drop round-trips the time with the
    // wrong offset, so future slots are wrongly rejected as "past".
    protected bool $useFilamentTimezone = true;

    protected CalendarViewType $calendarView = CalendarViewType::TimeGridDay;

    /**
     * Calendar options passed to the underlying @event-calendar instance.
     * Adds Month / Week / Day view toggles to the header toolbar.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return [
            'headerToolbar' => [
                'start' => 'prev,next today',
                'center' => 'title',
                'end' => 'dayGridMonth,timeGridWeek,timeGridDay',
            ],
            'buttonText' => [
                'today' => 'Today',
                'dayGridMonth' => 'Month',
                'timeGridWeek' => 'Week',
                'timeGridDay' => 'Day',
            ],
            // Expand to fit all slots so evening events are never clipped (page scrolls instead).
            'height' => 'auto',
            // No all-day appointments — hide the empty all-day row.
            'allDaySlot' => false,
            // Week/Day time grid: clinic hours, taller slots for readable event labels.
            'slotMinTime' => '08:00:00',
            'slotMaxTime' => '21:00:00',
            'slotDuration' => '00:30:00',
            'slotHeight' => 40,
            'nowIndicator' => true,
            // Month view: collapse crowded days into a "+N more" link.
            'dayMaxEvents' => true,
        ];
    }

    protected function getEvents(FetchInfo $info): Builder|array
    {
        return Appointment::query()
            ->with(['customer', 'status'])
            ->whereDate('scheduled_at', '>=', $info->start)
            ->whereDate('scheduled_at', '<=', $info->end);
    }

    /**
     * Clicking an event opens its full edit page (with status/reschedule/bill actions).
     */
    protected function onEventClick(EventClickInfo $info, Model $event, ?string $action = null): void
    {
        $this->redirect(EditAppointment::getUrl(['record' => $event->getKey()]));
    }

    /**
     * Clicking an empty day opens the create page with the date pre-filled.
     */
    protected function onDateClick(DateClickInfo $info): void
    {
        $this->redirect(CreateAppointment::getUrl([
            'scheduled_at' => $info->date->toDateTimeString(),
        ]));
    }

    /**
     * Dragging an event validates the move, reverts the visual drag, and asks for
     * confirmation before committing. Returning false reverts the drag.
     */
    protected function onEventDrop(EventDropInfo $info, Model $event): bool
    {
        if (! $event instanceof Appointment) {
            return false;
        }

        $newStart = $info->event->getStart();

        // Reject invalid moves outright (with a warning); valid moves go to confirmation.
        if (! $this->validateReschedule($event, $newStart)) {
            return false;
        }

        $this->mountAction('confirmReschedule', [
            'appointmentId' => $event->getKey(),
            'newStart' => $newStart->toIso8601String(),
        ]);

        // Always revert the visual drag — the move only applies once confirmed (then we refresh).
        return false;
    }

    public function confirmRescheduleAction(): Action
    {
        return Action::make('confirmReschedule')
            ->requiresConfirmation()
            ->modalHeading('Reschedule appointment?')
            ->modalIcon('heroicon-o-calendar-days')
            ->modalDescription(fn (array $arguments): string => 'Move this appointment to '
                .Carbon::parse($arguments['newStart'])->format('M j, Y g:i A').'?')
            ->modalSubmitActionLabel('Reschedule')
            ->action(function (array $arguments): void {
                $appointment = Appointment::query()->find($arguments['appointmentId']);

                if (! $appointment) {
                    return;
                }

                $newStart = Carbon::parse($arguments['newStart']);

                // Re-validate at confirm time — state may have changed since the drag.
                if (! $this->validateReschedule($appointment, $newStart)) {
                    return;
                }

                $this->performReschedule($appointment, $newStart);
                $this->refreshRecords();
            });
    }

    protected function validateReschedule(Appointment $appointment, CarbonInterface $newStart): bool
    {
        $appointment->loadMissing('status');

        if (! in_array($appointment->status?->name, ['pending', 'confirmed', 'rescheduled'], true)) {
            Notification::make()
                ->title('Cannot reschedule')
                ->body('Completed or cancelled appointments cannot be moved.')
                ->warning()
                ->send();

            return false;
        }

        if ($newStart->lt(now()->startOfDay())) {
            Notification::make()
                ->title('Invalid date')
                ->body('Appointments cannot be moved to a past date.')
                ->warning()
                ->send();

            return false;
        }

        if (Appointment::conflictsWith($newStart, $appointment->id)) {
            Notification::make()
                ->title('Time slot unavailable')
                ->body('Another appointment is within 30 minutes of that time.')
                ->warning()
                ->send();

            return false;
        }

        return true;
    }

    protected function performReschedule(Appointment $appointment, CarbonInterface $newStart): void
    {
        app(UpdateAppointmentStatus::class)->handle(
            $appointment,
            'rescheduled',
            scheduledAt: Carbon::parse($newStart),
        );

        Notification::make()
            ->title('Appointment rescheduled')
            ->body('Moved to '.$newStart->format('M j, Y g:i A').'.')
            ->success()
            ->send();
    }
}
