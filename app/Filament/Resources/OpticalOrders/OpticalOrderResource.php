<?php

namespace App\Filament\Resources\OpticalOrders;

use App\Enums\JobOrderStatus;
use App\Filament\Resources\OpticalOrders\Pages\EditOpticalOrder;
use App\Filament\Resources\OpticalOrders\Pages\ListOpticalOrders;
use App\Filament\Resources\OpticalOrders\Schemas\OpticalOrderForm;
use App\Filament\Resources\OpticalOrders\Tables\OpticalOrdersTable;
use App\Models\JobOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class OpticalOrderResource extends Resource
{
    protected static ?string $model = JobOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Optical';

    protected static bool $isGloballySearchable = true;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'job_order_number';

    protected static ?string $navigationLabel = 'Optical Orders';

    protected static ?string $modelLabel = 'Optical Order';

    protected static ?string $pluralModelLabel = 'Optical Orders';

    public static function getNavigationBadge(): ?string
    {
        $count = JobOrder::query()
            ->where('status', JobOrderStatus::ReadyForDispensing)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

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
            'edit' => EditOpticalOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['patient', 'items', 'encounter', 'prescription', 'quotation', 'billingRecord']);
    }
}
