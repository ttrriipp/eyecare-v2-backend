<?php

namespace App\Filament\Resources\BillingRecords;

use App\Enums\BillingRecordStatus;
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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Billing & Payments';

    protected static ?int $navigationSort = 10;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $recordTitleAttribute = 'billing_record_number';

    protected static bool $isGloballySearchable = true;

    public static function getNavigationBadge(): ?string
    {
        $count = BillingRecord::query()
            ->whereIn('status', [BillingRecordStatus::Unpaid, BillingRecordStatus::PartiallyPaid])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
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
