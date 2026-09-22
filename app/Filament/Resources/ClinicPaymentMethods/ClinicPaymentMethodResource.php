<?php

namespace App\Filament\Resources\ClinicPaymentMethods;

use App\Filament\Resources\ClinicPaymentMethods\Pages\CreateClinicPaymentMethod;
use App\Filament\Resources\ClinicPaymentMethods\Pages\EditClinicPaymentMethod;
use App\Filament\Resources\ClinicPaymentMethods\Pages\ListClinicPaymentMethods;
use App\Filament\Resources\ClinicPaymentMethods\Schemas\ClinicPaymentMethodForm;
use App\Filament\Resources\ClinicPaymentMethods\Tables\ClinicPaymentMethodsTable;
use App\Models\ClinicPaymentMethod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ClinicPaymentMethodResource extends Resource
{
    protected static ?string $model = ClinicPaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Payment Methods';

    protected static ?int $navigationSort = 35;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ClinicPaymentMethodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClinicPaymentMethodsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClinicPaymentMethods::route('/'),
            'create' => CreateClinicPaymentMethod::route('/create'),
            'edit' => EditClinicPaymentMethod::route('/{record}/edit'),
        ];
    }
}
