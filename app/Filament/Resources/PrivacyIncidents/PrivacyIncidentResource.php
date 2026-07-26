<?php

namespace App\Filament\Resources\PrivacyIncidents;

use App\Filament\Resources\PrivacyIncidents\Pages\EditPrivacyIncident;
use App\Filament\Resources\PrivacyIncidents\Pages\ListPrivacyIncidents;
use App\Filament\Resources\PrivacyIncidents\Tables\PrivacyIncidentsTable;
use App\Models\PrivacyIncident;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PrivacyIncidentResource extends Resource
{
    protected static ?string $model = PrivacyIncident::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $navigationLabel = 'Privacy Incidents';

    protected static ?string $modelLabel = 'Incident';

    protected static ?string $pluralModelLabel = 'Incidents';

    protected static ?int $navigationSort = 6;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return PrivacyIncidentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrivacyIncidents::route('/'),
            'edit' => EditPrivacyIncident::route('/{record}/edit'),
        ];
    }
}
