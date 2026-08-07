<?php

namespace App\Filament\Resources\VisitRatings\Tables;

use App\Actions\Ratings\ModerateVisitRating;
use App\Models\VisitRating;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisitRatingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patient.full_name')
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

                TextColumn::make('comment')
                    ->label('Comment')
                    ->limit(50)
                    ->wrap(),

                IconColumn::make('is_hidden')
                    ->label('Hidden')
                    ->boolean()
                    ->trueIcon('heroicon-o-eye-slash')
                    ->falseIcon('heroicon-o-eye')
                    ->trueColor('danger')
                    ->falseColor('gray'),
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

                Filter::make('hidden')
                    ->label('Hidden Only')
                    ->query(fn (Builder $query) => $query->where('is_hidden', true)),
            ])
            ->recordActions([
                Action::make('hideComment')
                    ->label('Hide Comment')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn (VisitRating $record): bool => ! $record->is_hidden)
                    ->requiresConfirmation()
                    ->modalHeading('Hide Comment')
                    ->modalDescription('This will hide the comment from public view. The star rating will still count toward averages.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason for hiding')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (VisitRating $record, array $data): void {
                        try {
                            app(ModerateVisitRating::class)->handle(
                                rating: $record,
                                reason: $data['reason'],
                                moderator: auth()->user(),
                            );

                            Notification::make()->title('Comment hidden')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('Cannot hide comment')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('restoreComment')
                    ->label('Restore Comment')
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->visible(fn (VisitRating $record): bool => $record->is_hidden)
                    ->requiresConfirmation()
                    ->modalHeading('Restore Comment')
                    ->modalDescription('This will make the comment visible again.')
                    ->action(function (VisitRating $record): void {
                        try {
                            app(ModerateVisitRating::class)->restore(
                                rating: $record,
                                moderator: auth()->user(),
                            );

                            Notification::make()->title('Comment restored')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('Cannot restore comment')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
