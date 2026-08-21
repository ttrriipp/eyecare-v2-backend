<?php

namespace App\Filament\Resources\Encounters;

use App\Enums\EncounterStatus;
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
use UnitEnum;

class EncounterResource extends Resource
{
    protected static ?string $model = Encounter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Consultations';

    protected static ?string $modelLabel = 'Consultation';

    protected static ?string $pluralModelLabel = 'Consultations';

    protected static ?string $recordTitleAttribute = 'encounter_number';

    protected static ?int $navigationSort = 10;

    protected static string|UnitEnum|null $navigationGroup = 'Clinical';

    protected static bool $isGloballySearchable = true;

    protected static string|NITENUM|null $NAVIGATIONGROUP = 'Patients & Clinical';

    public static function getNavigationBadge(): ?string
    {
        $count = Encounter::query()
            ->where('status', EncounterStatus::InProgress)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

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
        return parent::getEloquentQuery()->with(['patient', 'appointment', 'optometrist']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEncounters::route('/'),
            'edit' => EditEncounter::route('/{record}/edit'),
        ];
    }
}
