<?php

namespace App\Filament\Resources\LensTypes;

use App\Filament\Resources\LensTypes\Pages\CreateLensType;
use App\Filament\Resources\LensTypes\Pages\EditLensType;
use App\Filament\Resources\LensTypes\Pages\ListLensTypes;
use App\Filament\Resources\LensTypes\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\LensTypes\Schemas\LensTypeForm;
use App\Filament\Resources\LensTypes\Tables\LensTypesTable;
use App\Models\LensType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class LensTypeResource extends Resource
{
    protected static ?string $model = LensType::class;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static ?string $navigationLabel = 'Lens Types';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return LensTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LensTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLensTypes::route('/'),
            'create' => CreateLensType::route('/create'),
            'edit' => EditLensType::route('/{record}/edit'),
        ];
    }
}
