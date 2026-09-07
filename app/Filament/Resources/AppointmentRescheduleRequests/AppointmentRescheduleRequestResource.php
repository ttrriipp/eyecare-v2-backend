<?php

namespace App\Filament\Resources\AppointmentRescheduleRequests;

use App\Enums\AppointmentRescheduleRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Filament\Resources\AppointmentRescheduleRequests\Pages\ListAppointmentRescheduleRequests;
use App\Filament\Resources\AppointmentRescheduleRequests\Pages\ViewAppointmentRescheduleRequest;
use App\Filament\Resources\AppointmentRescheduleRequests\Schemas\AppointmentRescheduleRequestInfolist;
use App\Filament\Resources\AppointmentRescheduleRequests\Tables\AppointmentRescheduleRequestsTable;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AppointmentRescheduleRequestResource extends Resource
{
    protected static ?string $model = AppointmentRescheduleRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Today';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Reschedule Requests';

    protected static ?string $modelLabel = 'Reschedule Request';

    protected static ?string $pluralModelLabel = 'Reschedule Requests';

    protected static ?string $recordTitleAttribute = 'request_number';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = self::whereEffectivePending(AppointmentRescheduleRequest::query())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'appointment.appointmentType',
            'appointment.optometrist',
            'appointment.status',
            'patient',
            'user',
            'resolvedBy',
        ]);
    }

    public static function table(Table $table): Table
    {
        return AppointmentRescheduleRequestsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AppointmentRescheduleRequestInfolist::configure($schema);
    }

    public static function whereEffectivePending(Builder $query): Builder
    {
        return $query
            ->where('status', AppointmentRescheduleRequestStatus::Pending)
            ->where('expires_at', '>', now())
            ->whereHas('appointment', function (Builder $appointmentQuery): void {
                self::constrainEffectiveAppointment($appointmentQuery);
            });
    }

    public static function whereEffectiveExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('status', '!=', AppointmentRescheduleRequestStatus::Pending)
                ->orWhere(function (Builder $pendingQuery): void {
                    $pendingQuery
                        ->where('status', AppointmentRescheduleRequestStatus::Pending)
                        ->where(function (Builder $staleQuery): void {
                            $staleQuery
                                ->where('expires_at', '<=', now())
                                ->orWhereDoesntHave('appointment', function (Builder $appointmentQuery): void {
                                    self::constrainEffectiveAppointment($appointmentQuery);
                                });
                        });
                });
        });
    }

    public static function getRelations(): array
    {
        return [
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppointmentRescheduleRequests::route('/'),
            'view' => ViewAppointmentRescheduleRequest::route('/{record}'),
        ];
    }

    private static function constrainEffectiveAppointment(Builder $query): void
    {
        $appointmentTable = (new Appointment)->getTable();
        $requestTable = (new AppointmentRescheduleRequest)->getTable();

        $query
            ->where($appointmentTable.'.scheduled_at', '>', now())
            ->whereColumn(
                $appointmentTable.'.scheduled_at',
                $requestTable.'.current_scheduled_at',
            )
            ->whereHas('status', fn (Builder $statusQuery): Builder => $statusQuery
                ->where('name', AppointmentStatusName::Scheduled->value));
    }
}
