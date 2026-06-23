<?php

namespace App\Filament\Resources\Appointments\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('visitReason.name')
                    ->label('Visit reason'),
                TextColumn::make('status.name')
                    ->label('Status'),
                TextColumn::make('staff.name')
                    ->label('Assigned staff')
                    ->placeholder('Unassigned')
                    ->toggleable(),
                TextColumn::make('scheduled_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('contact_notes')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->relationship('status', 'name'),
                Filter::make('assigned_to_me')
                    ->label('Assigned to me')
                    ->query(fn (Builder $query): Builder => $query->where('staff_id', Auth::id()))
                    ->toggle(),
                Filter::make('scheduled_date')
                    ->schema([
                        DatePicker::make('scheduled_on')
                            ->label('Scheduled date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['scheduled_on'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('scheduled_at', $date),
                        );
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    RestoreAction::make(),
                    DeleteAction::make(),
                    ForceDeleteAction::make(),
                ]),
            ])
            ->defaultSort('scheduled_at', 'desc');
    }
}
