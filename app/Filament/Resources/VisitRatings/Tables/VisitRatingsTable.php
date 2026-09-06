<?php

namespace App\Filament\Resources\VisitRatings\Tables;

use App\Filament\Resources\VisitRatings\VisitRatingResource;
use App\Models\VisitRating;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VisitRatingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patient.full_name')
                    ->weight('bold')
                    ->label('Patient')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('appointment.appointment_number')
                    ->label('Appointment')
                    ->searchable(),

                TextColumn::make('appointment.scheduled_at')
                    ->label('Visit Date')
                    ->dateTime('M j, Y')
                    ->sortable(),

                TextColumn::make('optometrist.full_name')
                    ->label('Optometrist')
                    ->placeholder('—'),

                TextColumn::make('rating')
                    ->label('Rating')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 4 => 'success',
                        $state >= 3 => 'warning',
                        default => 'danger',
                    })
                    ->formatStateUsing(fn (int $state): string => str_repeat('★', $state).str_repeat('☆', 5 - $state)),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('rating')
                    ->label('Rating')
                    ->options([
                        5 => '5 Stars',
                        4 => '4 Stars',
                        3 => '3 Stars',
                        2 => '2 Stars',
                        1 => '1 Star',
                    ]),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (VisitRating $record) => VisitRatingResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
