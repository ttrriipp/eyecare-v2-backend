<?php

namespace App\Filament\Resources\VisitRatings;

use App\Filament\Resources\VisitRatings\Pages\ListVisitRatings;
use App\Filament\Resources\VisitRatings\Tables\VisitRatingsTable;
use App\Models\VisitRating;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class VisitRatingResource extends Resource
{
    protected static ?string $model = VisitRating::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Patients';

    protected static ?string $navigationLabel = 'Visit Feedback';

    protected static ?string $modelLabel = 'Visit Feedback';

    protected static ?string $pluralModelLabel = 'Visit Feedback';

    protected static ?int $navigationSort = 50;

    public static function canCreate(): bool
    {
        return false; // Feedback originates from mobile only
    }

    public static function table(Table $table): Table
    {
        return VisitRatingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVisitRatings::route('/'),
        ];
    }
}
