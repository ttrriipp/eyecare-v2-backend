<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatusName;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Role;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class TodaysScheduleWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        $roleNames = auth()->user()?->loadMissing('roles')->roles->pluck('name') ?? collect();
        $isOptometristOnly = $roleNames->contains(Role::Optometrist)
            && $roleNames->intersect([Role::Admin, Role::Staff])->isEmpty();
        $appointmentCount = Appointment::query()
            ->where('scheduled_at', '>=', today()->startOfDay())
            ->where('scheduled_at', '<', today()->addDay()->startOfDay())
            ->whereHas('status', fn (Builder $query): Builder => $query->whereNotIn('name', [
                AppointmentStatusName::Cancelled->value,
                AppointmentStatusName::NoShow->value,
            ]))
            ->when(
                $isOptometristOnly,
                fn (Builder $query): Builder => $query->where('optometrist_id', auth()->id()),
            )
            ->count();

        return "Today's Schedule · {$appointmentCount} ".($appointmentCount === 1 ? 'appointment' : 'appointments');
    }

    public function table(Table $table): Table
    {
        $roleNames = auth()->user()?->loadMissing('roles')->roles->pluck('name') ?? collect();
        $isOptometristOnly = $roleNames->contains(Role::Optometrist)
            && $roleNames->intersect([Role::Admin, Role::Staff])->isEmpty();
        $checkedInStatusId = AppointmentStatus::query()
            ->where('name', AppointmentStatusName::CheckedIn->value)
            ->value('id') ?? 0;

        return $table
            ->heading($this->getHeading())
            ->description('Checked-in patients first, then upcoming.')
            ->headerActions([
                Action::make('viewFullSchedule')
                    ->label('View Full Schedule')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(AppointmentResource::getUrl('index', [
                        'tableFilters' => [
                            'scheduled_date' => ['scheduled_on' => today()->toDateString()],
                        ],
                    ])),
            ])
            ->query(
                Appointment::query()
                    ->with(['patient', 'appointmentType', 'status', 'optometrist'])
                    ->where('scheduled_at', '>=', today()->startOfDay())
                    ->where('scheduled_at', '<', today()->addDay()->startOfDay())
                    ->whereHas('status', fn (Builder $query): Builder => $query->whereIn('name', [
                        AppointmentStatusName::Scheduled->value,
                        AppointmentStatusName::CheckedIn->value,
                    ]))
                    ->where(fn (Builder $query): Builder => $query
                        ->where('appointment_status_id', $checkedInStatusId)
                        ->orWhere('scheduled_at', '>=', now()))
                    ->when(
                        $isOptometristOnly,
                        fn (Builder $query): Builder => $query->where('optometrist_id', auth()->id()),
                    )
                    ->orderByRaw('CASE WHEN appointment_status_id = ? THEN 0 ELSE 1 END', [$checkedInStatusId])
                    ->orderBy('scheduled_at')
                    ->limit(5)
            )
            ->poll('30s')
            ->paginated(false)
            ->recordUrl(fn (Appointment $record): string => AppointmentResource::getUrl('edit', [
                'record' => $record,
            ]))
            ->columns([
                TextColumn::make('scheduled_at')
                    ->label('Time')
                    ->time('g:i A'),
                TextColumn::make('patient.full_name')
                    ->weight('bold')
                    ->label('Patient')
                    ->description(fn (Appointment $record): string => $record->patient?->patient_number ?? 'No patient number'),
                TextColumn::make('patient.phone')
                    ->label('Phone')
                    ->placeholder('—'),
                TextColumn::make('appointmentType.name')
                    ->weight('bold')
                    ->label('Visit')
                    ->wrap(),
                TextColumn::make('optometrist.full_name')
                    ->label('Optometrist')
                    ->state(fn (Appointment $record): string => $record->optometrist?->full_name ?? 'Unassigned')
                    ->visible(! $isOptometristOnly),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AppointmentStatusName::tryFrom($state)?->getLabel() ?? Str::headline($state))
                    ->color(fn (string $state): string => AppointmentStatusName::tryFrom($state)?->getColor() ?? 'gray'),
            ])
            ->emptyStateHeading('No appointments today')
            ->emptyStateDescription('Checked-in and upcoming patients will appear here.')
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays);
    }
}
