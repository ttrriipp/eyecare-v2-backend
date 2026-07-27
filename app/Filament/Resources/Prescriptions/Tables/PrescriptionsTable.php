<?php

namespace App\Filament\Resources\Prescriptions\Tables;

use App\Models\Prescription;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PrescriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patient.full_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('prescribed_at')
                    ->date()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->date()
                    ->sortable(),
                TextColumn::make('pd')
                    ->label('PD')
                    ->sortable(),
                TextColumn::make('createdBy.name')
                    ->label('Recorded by')
                    ->toggleable(),
            ])
            ->defaultSort('prescribed_at', 'desc')
            ->filters([
                TrashedFilter::make()
                    ->label('Show Archived')
                    ->placeholder('Active only')
                    ->trueLabel('Active and archived')
                    ->falseLabel('Archived only'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    RestoreAction::make()->label('Restore')->visible(fn (Prescription $record): bool => (auth()->user()?->isAdmin() ?? false) && $record->trashed()),
                    DeleteAction::make()->label('Archive')->icon('heroicon-o-archive-box')->modalIcon('heroicon-o-archive-box')->modalHeading('Archive prescription')->modalDescription('This will hide the prescription from active lists. It can be restored later from the "Show Archived" filter.')->modalSubmitActionLabel('Archive')->visible(fn (Prescription $record): bool => (auth()->user()?->isAdmin() ?? false) && ! $record->trashed()),
                ]),
            ]);
    }
}
