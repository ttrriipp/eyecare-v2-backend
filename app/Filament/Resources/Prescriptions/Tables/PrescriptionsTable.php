<?php

namespace App\Filament\Resources\Prescriptions\Tables;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Models\Prescription;
use Filament\Actions\Action;
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
                TextColumn::make('customer.name')
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
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('copy_to_new')
                        ->label('Copy to New')
                        ->icon('heroicon-o-document-duplicate')
                        ->color('info')
                        ->url(fn (Prescription $record): string => PrescriptionResource::getUrl('create', ['copy' => $record->id])),
                    EditAction::make(),
                    RestoreAction::make()->visible(fn () => auth()->user()?->isAdmin() ?? false),
                    DeleteAction::make()->visible(fn () => auth()->user()?->isAdmin() ?? false),
                ]),
            ]);
    }
}
