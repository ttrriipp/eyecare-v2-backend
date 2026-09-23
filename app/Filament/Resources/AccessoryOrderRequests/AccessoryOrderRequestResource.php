<?php

namespace App\Filament\Resources\AccessoryOrderRequests;

use App\Enums\AccessoryOrderRequestStatus;
use App\Filament\Resources\AccessoryOrderRequests\Pages\ListAccessoryOrderRequests;
use App\Filament\Resources\AccessoryOrderRequests\Pages\ViewAccessoryOrderRequest;
use App\Filament\Resources\AccessoryOrderRequests\Schemas\AccessoryOrderRequestInfolist;
use App\Filament\Resources\AccessoryOrderRequests\Tables\AccessoryOrderRequestsTable;
use App\Models\AccessoryOrderRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AccessoryOrderRequestResource extends Resource
{
    protected static ?string $model = AccessoryOrderRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Optical';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Order Requests';

    protected static ?string $modelLabel = 'Order Request';

    protected static ?string $pluralModelLabel = 'Order Requests';

    public static function getNavigationBadge(): ?string
    {
        $count = AccessoryOrderRequest::query()
            ->where('status', AccessoryOrderRequestStatus::Pending)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return AccessoryOrderRequestsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AccessoryOrderRequestInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccessoryOrderRequests::route('/'),
            'view' => ViewAccessoryOrderRequest::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'patient',
                'items',
                'resolvedBy',
                'jobOrder.activeBillingRecord',
                'discountProof.reviewedBy',
            ]);
    }
}
