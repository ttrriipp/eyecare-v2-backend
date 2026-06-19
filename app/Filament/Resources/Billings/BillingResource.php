<?php

namespace App\Filament\Resources\Billings;

use App\Filament\Resources\Billings\Pages\ListBillings;
use App\Filament\Resources\Billings\Pages\ViewBilling;
use App\Filament\Resources\Billings\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Billings\Schemas\BillingInfolist;
use App\Filament\Resources\Billings\Tables\BillingsTable;
use App\Models\Billing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BillingResource extends Resource
{
    protected static ?string $model = Billing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Billings';

    public static function infolist(Schema $schema): Schema
    {
        return BillingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BillingsTable::configure($table);
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
            'index' => ListBillings::route('/'),
            'view' => ViewBilling::route('/{record}'),
        ];
    }
}
