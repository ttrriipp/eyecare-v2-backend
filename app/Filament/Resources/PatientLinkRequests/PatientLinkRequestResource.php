<?php

namespace App\Filament\Resources\PatientLinkRequests;

use App\Filament\Resources\PatientLinkRequests\Pages\ListPatientLinkRequests;
use App\Filament\Resources\PatientLinkRequests\Pages\ViewPatientLinkRequest;
use App\Filament\Resources\PatientLinkRequests\Schemas\PatientLinkRequestForm;
use App\Filament\Resources\PatientLinkRequests\Tables\PatientLinkRequestsTable;
use App\Models\PatientLinkRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PatientLinkRequestResource extends Resource
{
    protected static ?string $model = PatientLinkRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'Patients';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'request_number';

    protected static ?string $navigationLabel = 'Link Requests';

    protected static ?string $modelLabel = 'Link Request';

    protected static ?string $pluralModelLabel = 'Link Requests';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return PatientLinkRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PatientLinkRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPatientLinkRequests::route('/'),
            'view' => ViewPatientLinkRequest::route('/{record}'),
        ];
    }
}
