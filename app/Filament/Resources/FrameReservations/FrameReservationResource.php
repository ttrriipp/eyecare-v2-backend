<?php

namespace App\Filament\Resources\FrameReservations;

use App\Filament\Resources\FrameReservations\Pages\EditFrameReservation;
use App\Filament\Resources\FrameReservations\Pages\ListFrameReservations;
use App\Filament\Resources\FrameReservations\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\FrameReservations\Schemas\FrameReservationForm;
use App\Filament\Resources\FrameReservations\Tables\FrameReservationsTable;
use App\Models\FrameReservation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FrameReservationResource extends Resource
{
    protected static ?string $model = FrameReservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmark;

    protected static ?string $navigationLabel = 'Frame Reservations';

    protected static ?string $modelLabel = 'Frame Reservation';

    protected static ?string $pluralModelLabel = 'Frame Reservations';

    protected static ?int $navigationSort = 30;

    protected static string|UnitEnum|null $navigationGroup = 'Optical';

    public static function form(Schema $schema): Schema
    {
        return FrameReservationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FrameReservationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFrameReservations::route('/'),
            'edit' => EditFrameReservation::route('/{record}/edit'),
        ];
    }
}
