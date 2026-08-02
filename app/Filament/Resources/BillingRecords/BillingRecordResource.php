<?php

namespace App\Filament\Resources\BillingRecords;

use App\Filament\Resources\BillingRecords\Pages\EditBillingRecord;
use App\Filament\Resources\BillingRecords\Pages\ListBillingRecords;
use App\Filament\Resources\BillingRecords\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\BillingRecords\Tables\BillingRecordsTable;
use App\Models\BillingRecord;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BillingRecordResource extends Resource
{
    protected static ?string $model = BillingRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $navigationLabel = 'Billing & Payments';

    protected static ?int $navigationSort = 1;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return BillingRecordsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillingRecords::route('/'),
            'edit' => EditBillingRecord::route('/{record}/edit'),
        ];
    }
}
