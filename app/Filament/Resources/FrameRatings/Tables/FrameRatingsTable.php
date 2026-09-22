<?php

namespace App\Filament\Resources\FrameRatings\Tables;

use App\Filament\Resources\FrameRatings\FrameRatingResource;
use App\Models\FrameRating;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FrameRatingsTable
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
                TextColumn::make('variant.product.name')
                    ->weight('bold')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('variant.name')
                    ->weight('bold')
                    ->label('Variant')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y')
                    ->sortable(),
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
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (FrameRating $record): string => FrameRatingResource::getUrl('edit', ['record' => $record])),

            ])
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
            ->defaultSort('created_at', 'desc');
    }
}
