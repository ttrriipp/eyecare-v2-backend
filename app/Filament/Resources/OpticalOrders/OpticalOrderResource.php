<?php

namespace App\Filament\Resources\OpticalOrders;

use App\Filament\Clusters\OpticalOrders\OpticalOrdersCluster;
use App\Filament\Resources\OpticalOrders\Pages\CreateOpticalOrder;
use App\Filament\Resources\OpticalOrders\Pages\EditOpticalOrder;
use App\Filament\Resources\OpticalOrders\Pages\ListOpticalOrders;
use App\Filament\Resources\OpticalOrders\Pages\ViewOpticalOrder;
use App\Filament\Resources\OpticalOrders\Schemas\OpticalOrderForm;
use App\Filament\Resources\OpticalOrders\Tables\OpticalOrdersTable;
use App\Models\JobOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OpticalOrderResource extends Resource
{
    protected static ?string $model = JobOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Optical';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'job_order_number';

    protected static ?string $navigationLabel = 'Orders';

    protected static ?string $modelLabel = 'Order';

    protected static ?string $pluralModelLabel = 'Orders';

    protected static ?string $cluster = OpticalOrdersCluster::class;

    public static function form(Schema $schema): Schema
    {
        return OpticalOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OpticalOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOpticalOrders::route('/'),
            'create' => CreateOpticalOrder::route('/create'),
            'edit' => EditOpticalOrder::route('/{record}/edit'),
            'view' => ViewOpticalOrder::route('/{record}'),
        ];
    }
}
