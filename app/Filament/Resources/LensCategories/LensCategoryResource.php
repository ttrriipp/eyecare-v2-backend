<?php

namespace App\Filament\Resources\LensCategories;

use App\Filament\Resources\LensCategories\Pages\CreateLensCategory;
use App\Filament\Resources\LensCategories\Pages\EditLensCategory;
use App\Filament\Resources\LensCategories\Pages\ListLensCategories;
use App\Filament\Resources\LensCategories\Schemas\LensCategoryForm;
use App\Filament\Resources\LensCategories\Tables\LensCategoriesTable;
use App\Models\LensCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class LensCategoryResource extends Resource
{
    protected static ?string $model = LensCategory::class;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewfinderCircle;

    protected static ?string $navigationLabel = 'Lens Categories';

    protected static ?int $navigationSort = 50;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return LensCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LensCategoriesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withTrashed();
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withTrashed();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLensCategories::route('/'),
            'create' => CreateLensCategory::route('/create'),
            'edit' => EditLensCategory::route('/{record}/edit'),
        ];
    }
}
