<?php

namespace App\Filament\Resources\ServiceRecords;

use App\Filament\Resources\ServiceRecords\Pages\CreateServiceRecord;
use App\Filament\Resources\ServiceRecords\Pages\EditServiceRecord;
use App\Filament\Resources\ServiceRecords\Pages\ListServiceRecords;
use App\Filament\Resources\ServiceRecords\Schemas\ServiceRecordForm;
use App\Filament\Resources\ServiceRecords\Tables\ServiceRecordsTable;
use App\Models\ServiceRecord;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ServiceRecordResource extends Resource
{
    protected static ?string $model = ServiceRecord::class;

    protected static string|UnitEnum|null $navigationGroup = 'Orders & Billing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Service Records';

    protected static ?int $navigationSort = 12;

    public static function form(Schema $schema): Schema
    {
        return ServiceRecordForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceRecordsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceRecords::route('/'),
            'create' => CreateServiceRecord::route('/create'),
            'edit' => EditServiceRecord::route('/{record}/edit'),
        ];
    }
}
