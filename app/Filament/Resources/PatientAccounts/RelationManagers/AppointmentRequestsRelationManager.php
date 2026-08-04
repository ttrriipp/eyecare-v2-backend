<?php

namespace App\Filament\Resources\PatientAccounts\RelationManagers;

use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\Schemas\AppointmentRequestForm;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AppointmentRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'appointmentRequests';

    protected static ?string $title = 'Appointment Requests';

    public function form(Schema $schema): Schema
    {
        return AppointmentRequestForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_number')
                    ->label('Request #')
                    ->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AppointmentRequestStatus $state) => match ($state) {
                        AppointmentRequestStatus::Pending => 'warning',
                        AppointmentRequestStatus::Accepted => 'success',
                        AppointmentRequestStatus::Rejected => 'danger',
                        AppointmentRequestStatus::Cancelled => 'gray',
                        AppointmentRequestStatus::Expired => 'gray',
                    }),

                TextColumn::make('scheduled_at')
                    ->label('Preferred Time')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([5])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                ]),
            ])
            ->heading(null);
    }
}
