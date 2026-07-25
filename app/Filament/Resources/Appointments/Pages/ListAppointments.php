<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Actions\Appointments\CreateWalkInAppointment;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Widgets\AppointmentStatsWidget;
use App\Models\Patient;
use App\Models\User;
use App\Models\VisitReason;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    protected string $view = 'filament.resources.appointments.pages.list-appointments';

    public bool $showCalendar = false;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addWalkIn')
                ->label('Add Walk-in')
                ->icon('heroicon-o-user-plus')
                ->color('warning')
                ->schema([
                    Select::make('patient_id')
                        ->label('Patient')
                        ->relationship('patient', 'full_name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            TextInput::make('full_name')->required(),
                            TextInput::make('phone')->required()->tel(),
                        ])
                        ->createOptionUsing(fn (array $data): int => Patient::query()->create($data)->getKey()),
                    Select::make('visit_reason_id')
                        ->label('Visit reason')
                        ->options(fn () => VisitReason::query()->orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->preload(),
                    Select::make('optometrist_id')
                        ->label('Optometrist')
                        ->options(fn () => User::query()->optometrists()->orderBy('name')->pluck('name', 'id'))
                        ->nullable()
                        ->searchable()
                        ->preload()
                        ->placeholder('Assign later'),
                    Textarea::make('contact_notes')
                        ->label('Notes')
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    app(CreateWalkInAppointment::class)->handle(
                        patient: Patient::query()->findOrFail($data['patient_id']),
                        visitReason: VisitReason::query()->findOrFail($data['visit_reason_id']),
                        staff: auth()->user(),
                        optometrist: filled($data['optometrist_id'] ?? null)
                            ? User::query()->findOrFail($data['optometrist_id'])
                            : null,
                        contactNotes: $data['contact_notes'] ?? null,
                    );

                    Notification::make()->title('Walk-in added to queue')->success()->send();
                }),
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [AppointmentStatsWidget::class];
    }

    public function updatedActiveTab(): void
    {
        $this->dispatch('appointment-tab-changed', tab: $this->activeTab);
    }

    public function getTabs(): array
    {
        $statuses = ['pending', 'confirmed', 'arrived', 'completed', 'no_show', 'cancelled'];

        $tabs = ['all' => Tab::make('All')];

        foreach ($statuses as $status) {
            $label = ucwords(str_replace('_', ' ', $status));
            $tabs[$status] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query) => $this->isWalkInQueueFilterActive()
                    ? $query
                    : $query->whereHas(
                        'status',
                        fn (Builder $q) => $q->where('name', $status)
                    ));
        }

        return $tabs;
    }

    private function isWalkInQueueFilterActive(): bool
    {
        return (bool) data_get($this->tableFilters, 'walk_in_queue.isActive', false);
    }
}
