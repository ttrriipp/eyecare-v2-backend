<?php

namespace App\Filament\Widgets;

use App\Models\Appointment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TodaysScheduleWidget extends TableWidget
{
    protected static ?string $heading = "Today's Schedule";

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Appointment::query()
                    ->with(['customer', 'visitReason', 'status'])
                    ->whereDate('scheduled_at', today())
                    ->whereHas('status', fn ($q) => $q->whereIn('name', ['pending', 'confirmed', 'rescheduled']))
                    ->orderBy('scheduled_at')
                    ->limit(5)
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('scheduled_at')
                    ->label('Time')
                    ->time('g:i A'),
                TextColumn::make('customer.name')
                    ->label('Patient'),
                TextColumn::make('customer.phone')
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
                        'rescheduled' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->emptyStateHeading('No appointments today')
            ->emptyStateDescription('All clear — no upcoming appointments scheduled for today.')
            ->emptyStateIcon('heroicon-o-calendar');
    }
}
