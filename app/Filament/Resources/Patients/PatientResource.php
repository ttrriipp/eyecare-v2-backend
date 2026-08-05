<?php

namespace App\Filament\Resources\Patients;

use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\RelationManagers\AppointmentsRelationManager;
use App\Filament\Resources\Patients\RelationManagers\HealthRecordRelationManager;
use App\Filament\Resources\Patients\RelationManagers\InvitationHistoryRelationManager;
use App\Filament\Resources\Patients\RelationManagers\PrescriptionsRelationManager;
use App\Filament\Resources\Patients\Schemas\PatientForm;
use App\Filament\Resources\Patients\Tables\PatientsTable;
use App\Models\Patient;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PatientResource extends Resource
{
    protected static ?string $model = Patient::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Patient Records';

    protected static ?string $modelLabel = 'Patient Record';

    protected static ?string $pluralModelLabel = 'Patient Records';

    protected static ?int $navigationSort = 10;

    protected static string|UnitEnum|null $navigationGroup = 'Patients';

    protected static bool $isGloballySearchable = true;

    protected static ?string $recordTitleAttribute = 'first_name';

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'phone', 'contact_email', 'patient_number'];
    }

    public static function form(Schema $schema): Schema
    {
        return PatientForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PatientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PrescriptionsRelationManager::class,
            AppointmentsRelationManager::class,
            HealthRecordRelationManager::class,
            InvitationHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPatients::route('/'),
            'create' => CreatePatient::route('/create'),
            'edit' => EditPatient::route('/{record}/edit'),
        ];
    }
}
