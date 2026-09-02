<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Enums\EncounterStatus;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Models\Encounter;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EncountersRelationManager extends RelationManager
{
    protected static string $relationship = 'encounters';

    protected static ?string $title = 'Consultations';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('encounter_number')->label('Consultation #')->searchable()->sortable(),
                TextColumn::make('optometrist.first_name')
                    ->label('Optometrist')
                    ->state(fn (Encounter $record): string => $record->optometrist?->full_name ?? '—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (Encounter $record): string => match ($record->status) {
                        EncounterStatus::Planned => 'gray',
                        EncounterStatus::InProgress => 'warning',
                        EncounterStatus::Completed => 'success',
                        EncounterStatus::Cancelled => 'danger',
                        EncounterStatus::Voided => 'danger',
                    })
                    ->formatStateUsing(fn (EncounterStatus $state): string => match ($state) {
                        EncounterStatus::Planned => 'Planned',
                        EncounterStatus::InProgress => 'In Progress',
                        EncounterStatus::Completed => 'Completed',
                        EncounterStatus::Cancelled => 'Cancelled',
                        EncounterStatus::Voided => 'Voided',
                    }),
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => EncounterResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false);
    }
}
