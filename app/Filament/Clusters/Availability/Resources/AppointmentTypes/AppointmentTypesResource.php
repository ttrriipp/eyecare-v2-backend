<?php

namespace App\Filament\Clusters\Availability\Resources\AppointmentTypes;

use App\Filament\Clusters\Availability\AvailabilityCluster;
use App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages\CreateAppointmentTypes;
use App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages\EditAppointmentTypes;
use App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages\ListAppointmentTypes;
use App\Filament\Clusters\Availability\Resources\AppointmentTypes\Schemas\AppointmentTypesForm;
use App\Filament\Clusters\Availability\Resources\AppointmentTypes\Tables\AppointmentTypesTable;
use App\Models\AppointmentType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;

class AppointmentTypesResource extends Resource
{
    protected static ?string $model = AppointmentType::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $cluster = AvailabilityCluster::class;

    protected static ?string $navigationLabel = 'Appointment Types';

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getMaxContentWidth(): Width|string|null
    {
        return Width::SevenExtraLarge;
    }

    public static function form(Schema $schema): Schema
    {
        return AppointmentTypesForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppointmentTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppointmentTypes::route('/'),
            'create' => CreateAppointmentTypes::route('/create'),
            'edit' => EditAppointmentTypes::route('/{record}/edit'),
        ];
    }
}
