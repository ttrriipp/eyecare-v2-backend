<?php

namespace App\Filament\Resources\Appointments\Widgets;

use App\Models\Appointment;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Builder;

class AppointmentCalendarWidget extends CalendarWidget
{
    protected bool $eventClickEnabled = true;

    protected function getEvents(FetchInfo $info): Builder|array
    {
        return Appointment::query()
            ->with(['customer', 'status'])
            ->whereDate('scheduled_at', '>=', $info->start)
            ->whereDate('scheduled_at', '<=', $info->end);
    }
}
