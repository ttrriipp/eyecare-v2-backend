<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Models\Prescription;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PrescriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'prescriptions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('prescribed_at')->label('Date')->date('M j, Y')->sortable(),
                TextColumn::make('version_status')
                    ->label('Version')
                    ->state(fn (Prescription $record): string => $record->next_prescription_exists
                        ? 'Superseded'
                        : ($record->previous_prescription_id === null ? 'Original' : 'Current amendment'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Superseded' ? 'warning' : 'success'),
                TextColumn::make('encounter.encounter_number')
                    ->label('Encounter')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('author.name')
                    ->label('Optometrist')
                    ->searchable()
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['encounter', 'author'])
                ->withExists('nextPrescription'))
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => PrescriptionResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('prescribed_at', 'desc')
            ->paginated(false);
    }
}
