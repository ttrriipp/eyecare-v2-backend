<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Widgets\AppointmentCalendarWidget;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\Page;

class CalendarAppointments extends Page
{
    protected static string $resource = AppointmentResource::class;

    protected string $view = 'filament.resources.appointments.pages.calendar-appointments';

    public function getTitle(): string
    {
        return 'Appointment Calendar';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('list')
                ->label('List')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->outlined()
                ->url(AppointmentResource::getUrl('index')),
            CreateAction::make(),
        ];
    }

    protected function getCalendarWidget(): string
    {
        return AppointmentCalendarWidget::class;
    }
}
