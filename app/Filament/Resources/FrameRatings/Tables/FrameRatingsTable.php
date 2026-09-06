<?php

namespace App\Filament\Resources\FrameRatings\Tables;

use App\Actions\Ratings\ModerateFrameRating;
use App\Models\FrameRating;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FrameRatingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patient.first_name')
                    ->weight('bold')
                    ->label('Patient'),
                TextColumn::make('variant.name')
                    ->weight('bold')
                    ->label('Frame')
                    ->searchable(),
                TextColumn::make('rating')
                    ->label('Stars')
                    ->sortable(),
                TextColumn::make('comment')
                    ->label('Comment')
                    ->limit(50)
                    ->placeholder('—'),
                IconColumn::make('is_hidden')
                    ->label('Hidden')
                    ->boolean(),
                TextColumn::make('moderated_at')
                    ->label('Moderated')
                    ->dateTime('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Filter::make('hidden')
                    ->query(fn (Builder $query): Builder => $query->where('is_hidden', true))
                    ->label('Hidden only'),
            ])
            ->recordActions([
                Action::make('hideComment')
                    ->label('Hide Comment')
                    ->icon('heroicon-o-eye-slash')
                    ->color('warning')
                    ->visible(fn (FrameRating $record): bool => ! $record->is_hidden)
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required(),
                    ])
                    ->action(function (FrameRating $record, array $data): void {
                        app(ModerateFrameRating::class)->handle(
                            $record,
                            $data['reason'],
                            auth()->user(),
                        );
                    }),

                Action::make('restoreComment')
                    ->label('Restore Comment')
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->visible(fn (FrameRating $record): bool => $record->is_hidden)
                    ->requiresConfirmation()
                    ->action(function (FrameRating $record): void {
                        app(ModerateFrameRating::class)->restore(
                            $record,
                            auth()->user(),
                        );
                    }),

                EditAction::make()->label('View'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
