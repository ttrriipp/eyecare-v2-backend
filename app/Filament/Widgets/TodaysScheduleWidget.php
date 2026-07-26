<?php

namespace App\Filament\Widgets;

use App\Enums\JobOrderStatus;
use App\Models\Appointment;
use App\Models\JobOrder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TodaysScheduleWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        $readyCount = JobOrder::query()
            ->where('status', JobOrderStatus::ReadyForDispensing)
            ->count();

        $heading = "Today's Schedule";

        if ($readyCount > 0) {
            $heading .= " · {$readyCount} job order".($readyCount > 1 ? 's' : '').' ready for dispensing';
        }

        return $heading;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Appointment::query()
                    ->with(['patient', 'visitReason', 'status'])
                    ->whereDate('scheduled_at', today())
                    ->whereHas('status', fn ($q) => $q->whereIn('name', ['pending', 'confirmed', 'arrived']))
                    ->orderBy('scheduled_at')
                    ->limit(5)
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('scheduled_at')
                    ->label('Time')
                    ->time('g:i A'),
                TextColumn::make('patient.full_name')
                    ->label('Patient'),
                TextColumn::make('patient.phone')
                    ->label('Phone')
                    ->default('—'),
                TextColumn::make('visitReason.name')
                    ->label('Visit Reason'),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'confirmed' => 'info',
                        'arrived' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->emptyStateHeading('No appointments today')
            ->emptyStateDescription('All clear — no upcoming appointments scheduled for today.')
            ->emptyStateIcon('heroicon-o-calendar');
    }
}
