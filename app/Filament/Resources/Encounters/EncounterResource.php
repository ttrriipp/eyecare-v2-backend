<?php

namespace App\Filament\Resources\Encounters;

use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Filament\Resources\Encounters\Pages\ListEncounters;
use App\Filament\Resources\Encounters\Schemas\EncounterForm;
use App\Filament\Resources\Encounters\Tables\EncountersTable;
use App\Models\Encounter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EncounterResource extends Resource
{
    protected static ?string $model = Encounter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Encounters';

    protected static ?string $modelLabel = 'Encounter';

    protected static ?string $pluralModelLabel = 'Encounters';

    protected static ?string $recordTitleAttribute = 'encounter_number';

    protected static ?int $navigationSort = 2;

    protected static string|NITENUM|null $NAVIGATIONGROUP = 'Patients & Clinical';

    public static function form(Schema $schema): Schema
    {
        return EncounterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EncountersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['patient', 'appointment', 'optometrist', 'intake']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEncounters::route('/'),
            'edit' => EditEncounter::route('/{record}/edit'),
        ];
    }
}
