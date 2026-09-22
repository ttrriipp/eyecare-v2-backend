<?php

namespace App\Filament\Resources\FrameRatings\Tables;

use App\Filament\Resources\FrameRatings\FrameRatingResource;
use App\Models\FrameRating;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FrameRatingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patient.first_name')
                    ->weight('bold')
                    ->label('Patient'),
                TextColumn::make('variant.product.name')
                    ->weight('bold')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('variant.name')
                    ->weight('bold')
                    ->label('Variant')
                    ->searchable(),
                TextColumn::make('rating')
                    ->label('Stars')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (FrameRating $record): string => FrameRatingResource::getUrl('edit', ['record' => $record])),

            ])
            ->defaultSort('created_at', 'desc');
    }
}
