<?php

namespace App\Filament\Pages;

use App\Enums\JobOrderStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Models\Role;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $roleNames = auth()->user()?->loadMissing('roles')->roles->pluck('name') ?? collect();

        return [
            Action::make('newAppointment')
                ->label('New Appointment')
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->url(AppointmentResource::getUrl('create')),

            Action::make('myEncounters')
                ->label('My Encounters')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->visible($roleNames->contains(Role::Optometrist))
                ->url(EncounterResource::getUrl('index', [
                    'activeTab' => 'in_progress',
                    'tableFilters' => [
                        'optometrist' => ['value' => auth()->id()],
                    ],
                ])),

            Action::make('readyForPickup')
                ->label('Ready for Pickup')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('gray')
                ->visible($roleNames->intersect([Role::Admin, Role::Staff])->isNotEmpty())
                ->url(OpticalOrderResource::getUrl('index', [
                    'tableFilters' => [
                        'status' => ['value' => JobOrderStatus::ReadyForDispensing->value],
                    ],
                ])),
        ];
    }
}
