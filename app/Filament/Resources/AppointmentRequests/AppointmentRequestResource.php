<?php

namespace App\Filament\Resources\AppointmentRequests;

use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\Pages\ListAppointmentRequests;
use App\Filament\Resources\AppointmentRequests\Pages\ViewAppointmentRequest;
use App\Filament\Resources\AppointmentRequests\Schemas\AppointmentRequestForm;
use App\Filament\Resources\AppointmentRequests\Tables\AppointmentRequestsTable;
use App\Models\AppointmentRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AppointmentRequestResource extends Resource
{
    protected static ?string $model = AppointmentRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Today';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'request_number';

    public static function canCreate(): bool
    {
        return false; // Requests are created by patients via API
    }

    public static function getNavigationBadge(): ?string
    {
        $count = AppointmentRequest::query()
            ->where('status', AppointmentRequestStatus::Pending)
            ->where('expires_at', '>', now())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return AppointmentRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppointmentRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppointmentRequests::route('/'),
            'view' => ViewAppointmentRequest::route('/{record}'),
        ];
    }
}
