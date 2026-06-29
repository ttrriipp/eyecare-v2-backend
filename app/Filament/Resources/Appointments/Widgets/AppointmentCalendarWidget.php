<?php

namespace App\Filament\Resources\Appointments\Widgets;

use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Models\Appointment;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
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
     * Dragging an event reschedules it. Returning false reverts the drag.
     */
    protected function onEventDrop(EventDropInfo $info, Model $event): bool
    {
        if (! $event instanceof Appointment) {
            return false;
        }

        return $this->attemptReschedule($event, $info->event->getStart());
    }

    protected function attemptReschedule(Appointment $appointment, CarbonInterface $newStart): bool
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

        if ($newStart->isPast()) {
            Notification::make()
                ->title('Invalid time')
                ->body('Appointments cannot be moved to a past time.')
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

        return true;
    }
}
