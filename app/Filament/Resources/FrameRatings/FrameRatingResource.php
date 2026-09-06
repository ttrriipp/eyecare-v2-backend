<?php

namespace App\Filament\Resources\FrameRatings;

use App\Filament\Resources\FrameRatings\Pages\EditFrameRating;
use App\Filament\Resources\FrameRatings\Pages\ListFrameRatings;
use App\Filament\Resources\FrameRatings\Tables\FrameRatingsTable;
use App\Models\FrameRating;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FrameRatingResource extends Resource
{
    protected static ?string $model = FrameRating::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $navigationLabel = 'Frame Ratings';

    protected static ?string $modelLabel = 'Rating';

    protected static ?string $pluralModelLabel = 'Ratings';

    protected static ?int $navigationSort = 40;

    protected static string|UnitEnum|null $navigationGroup = 'Optical';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->is_active === true
            && ($user->isAdmin() || $user->isStaff());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return FrameRatingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFrameRatings::route('/'),
            'edit' => EditFrameRating::route('/{record}/edit'),
        ];
    }
}
