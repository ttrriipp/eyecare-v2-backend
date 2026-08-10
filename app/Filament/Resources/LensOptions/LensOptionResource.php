<?php

namespace App\Filament\Resources\LensOptions;

use App\Filament\Resources\LensOptions\Pages\CreateLensOption;
use App\Filament\Resources\LensOptions\Pages\EditLensOption;
use App\Filament\Resources\LensOptions\Pages\ListLensOptions;
use App\Filament\Resources\LensOptions\Schemas\LensOptionForm;
use App\Filament\Resources\LensOptions\Tables\LensOptionsTable;
use App\Models\LensOption;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class LensOptionResource extends Resource
{
    protected static ?string $model = LensOption::class;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?string $navigationLabel = 'Lens Options';

    protected static ?int $navigationSort = 55;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return LensOptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LensOptionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLensOptions::route('/'),
            'create' => CreateLensOption::route('/create'),
            'edit' => EditLensOption::route('/{record}/edit'),
        ];
    }
}
