<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Widgets\AppointmentStatsWidget;
use App\Models\AppointmentRequest;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [AppointmentStatsWidget::class];
    }

    public function updatedActiveTab(): void
    {
        if ($this->activeTab === 'requests') {
            $this->redirectRoute('filament.admin.resources.appointment-requests.index');

            return;
        }
    }

    public function getTabs(): array
    {
        $pendingCount = AppointmentRequest::where('status', 'pending')
            ->where('expires_at', '>', now())
            ->count();

        $statuses = ['scheduled', 'checked_in', 'fulfilled', 'no_show', 'cancelled'];

        $tabs = [
            'all' => Tab::make('All'),
            'requests' => Tab::make($pendingCount > 0 ? "Requests ({$pendingCount})" : 'Requests'),
        ];

        foreach ($statuses as $status) {
            $label = ucwords(str_replace('_', ' ', $status));
            $tabs[$status] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('status', fn (Builder $q) => $q->where('name', $status))
                );
        }

        return $tabs;
    }
}
