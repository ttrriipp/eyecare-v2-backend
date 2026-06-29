<?php

namespace App\Filament\Widgets;

use App\Models\Feedback;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentFeedbackWidget extends TableWidget
{
    protected static ?string $heading = 'Recent Feedback';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Feedback::query()
                    ->with(['customer', 'appointment', 'order'])
                    ->latest()
                    ->limit(5)
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Customer'),
                TextColumn::make('rating')
                    ->label('Rating'),
                TextColumn::make('appointment.id')
                    ->label('Appointment')
                    ->default('—')
                    ->formatStateUsing(fn ($state) => $state ? "#{$state}" : '—'),
                TextColumn::make('order.order_number')
                    ->label('Order')
                    ->default('—'),
                IconColumn::make('staff_reply')
                    ->label('Replied')
                    ->boolean()
                    ->getStateUsing(fn ($record) => ! is_null($record->staff_reply)),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
