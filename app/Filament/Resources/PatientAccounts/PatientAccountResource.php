<?php

namespace App\Filament\Resources\PatientAccounts;

use App\Filament\Resources\PatientAccounts\Pages\ListPatientAccounts;
use App\Filament\Resources\PatientAccounts\Pages\ViewPatientAccount;
use App\Filament\Resources\PatientAccounts\RelationManagers\AppointmentRequestsRelationManager;
use App\Filament\Resources\PatientAccounts\RelationManagers\DeviceSessionsRelationManager;
use App\Filament\Resources\PatientAccounts\RelationManagers\LinkRequestsRelationManager;
use App\Filament\Resources\PatientAccounts\Schemas\PatientAccountForm;
use App\Filament\Resources\PatientAccounts\Tables\PatientAccountsTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PatientAccountResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Patients';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Patient Accounts';

    protected static ?string $modelLabel = 'Patient Account';

    protected static ?string $pluralModelLabel = 'Patient Accounts';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return false; // Patient accounts are created via mobile registration
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('roles', fn (Builder $q) => $q->where('name', 'patient'));
    }

    public static function form(Schema $schema): Schema
    {
        return PatientAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PatientAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AppointmentRequestsRelationManager::class,
            LinkRequestsRelationManager::class,
            DeviceSessionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPatientAccounts::route('/'),
            'view' => ViewPatientAccount::route('/{record}'),
        ];
    }
}
