<?php

namespace App\Filament\Resources\VisitReasons;

use App\Filament\Resources\VisitReasons\Pages\CreateVisitReason;
use App\Filament\Resources\VisitReasons\Pages\EditVisitReason;
use App\Filament\Resources\VisitReasons\Pages\ListVisitReasons;
use App\Filament\Resources\VisitReasons\Schemas\VisitReasonForm;
use App\Filament\Resources\VisitReasons\Tables\VisitReasonsTable;
use App\Models\VisitReason;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class VisitReasonResource extends Resource
{
    protected static ?string $model = VisitReason::class;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Visit Reasons';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return VisitReasonForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VisitReasonsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVisitReasons::route('/'),
            'create' => CreateVisitReason::route('/create'),
            'edit' => EditVisitReason::route('/{record}/edit'),
        ];
    }
}
