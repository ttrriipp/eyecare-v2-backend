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
use Illuminate\Support\Str;

class AppointmentRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_number')
                    ->label('Number')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->placeholder(fn (AppointmentRequest $record): string => $record->user->name ?? '—')
                    ->searchable(),

                TextColumn::make('scheduled_at')
                    ->label('Preferred Time')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),

                TextColumn::make('reason_for_visit')
                    ->label('Reason')
                    ->limit(40)
                    ->wrap(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AppointmentRequestStatus $state) => match ($state) {
                        AppointmentRequestStatus::Pending => 'warning',
                        AppointmentRequestStatus::Accepted => 'success',
                        AppointmentRequestStatus::Rejected => 'danger',
                        AppointmentRequestStatus::Cancelled => 'gray',
                        AppointmentRequestStatus::Expired => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),

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
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Review')
                    ->url(fn (AppointmentRequest $record) => AppointmentRequestResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
