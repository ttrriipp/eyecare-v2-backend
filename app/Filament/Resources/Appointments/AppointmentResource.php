<?php

namespace App\Filament\Resources\Appointments;

use App\Filament\Resources\Appointments\Pages\AppointmentRequestsPage;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\RelationManagers\FrameReservationsRelationManager;
use App\Filament\Resources\Appointments\Schemas\AppointmentForm;
use App\Filament\Resources\Appointments\Tables\AppointmentsTable;
use App\Models\Appointment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AppointmentResource extends Resource
{
    protected static ?string $model = Appointment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $navigationLabel = 'Appointments';

    protected static ?int $navigationSort = 1;

    protected static bool $isGloballySearchable = true;

    protected static ?string $recordTitleAttribute = 'appointment_number';

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['appointment_number', 'patient.first_name', 'patient.last_name', 'patient.phone'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->appointment_number ?? "Appointment #{$record->id}";
    }

    public static function form(Schema $schema): Schema
    {
        return AppointmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppointmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            FrameReservationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppointments::route('/'),
            'create' => CreateAppointment::route('/create'),
            'edit' => EditAppointment::route('/{record}/edit'),
            'requests' => AppointmentRequestsPage::route('/requests'),
        ];
    }
}
