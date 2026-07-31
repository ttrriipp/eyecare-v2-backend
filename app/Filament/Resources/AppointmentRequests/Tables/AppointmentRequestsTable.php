<?php

namespace App\Filament\Resources\AppointmentRequests\Tables;

use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Models\AppointmentRequest;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AppointmentRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_number')
                    ->label('Request #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Account Owner')
                    ->searchable(),

                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->placeholder('Unlinked')
                    ->searchable(),

                TextColumn::make('scheduled_at')
                    ->label('Preferred Time')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),

                TextColumn::make('encrypted_reason_for_visit')
                    ->label('Reason')
                    ->limit(50)
                    ->wrap(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AppointmentRequestStatus $state) => match ($state) {
                        AppointmentRequestStatus::Pending => 'warning',
                        AppointmentRequestStatus::Accepted => 'success',
                        AppointmentRequestStatus::Rejected => 'danger',
                        AppointmentRequestStatus::Cancelled => 'gray',
                        AppointmentRequestStatus::Expired => 'gray',
                    }),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('M j, g:i A')
                    ->sortable()
                    ->color(fn (AppointmentRequest $record) => $record->expires_at->isPast() ? 'danger' : null),

                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('M j, g:i A')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(AppointmentRequestStatus::class),

                SelectFilter::make('patient_id')
                    ->label('Link Status')
                    ->options([
                        'linked' => 'Linked',
                        'unlinked' => 'Needs Patient',
                    ])
                    ->query(function ($query, $data) {
                        if ($data['value'] === 'linked') {
                            $query->whereNotNull('patient_id');
                        } elseif ($data['value'] === 'unlinked') {
                            $query->whereNull('patient_id');
                        }
                    }),
            ])
            ->recordActions([
                Action::make('view')
                    ->url(fn (AppointmentRequest $record) => AppointmentRequestResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
