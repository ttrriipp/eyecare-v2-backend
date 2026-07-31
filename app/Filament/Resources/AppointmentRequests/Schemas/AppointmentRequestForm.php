<?php

namespace App\Filament\Resources\AppointmentRequests\Schemas;

use App\Enums\AppointmentRequestStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AppointmentRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('request_number')
                    ->label('Request #')
                    ->disabled(),

                Placeholder::make('account_owner')
                    ->label('Account Owner')
                    ->content(fn ($record) => $record?->user?->name ?? '—'),

                Placeholder::make('patient_name')
                    ->label('Patient')
                    ->content(fn ($record) => $record?->patient?->full_name ?? 'Unlinked — needs resolution'),

                Placeholder::make('preferred_time')
                    ->label('Preferred Time')
                    ->content(fn ($record) => $record?->scheduled_at?->format('M j, Y g:i A') ?? '—'),

                Textarea::make('encrypted_reason_for_visit')
                    ->label('Reason for Visit')
                    ->disabled()
                    ->columnSpanFull(),

                Select::make('status')
                    ->options(AppointmentRequestStatus::class)
                    ->disabled(),

                DateTimePicker::make('expires_at')
                    ->label('Expires')
                    ->disabled(),

                Placeholder::make('resolved_by')
                    ->label('Resolved By')
                    ->content(fn ($record) => $record?->resolvedBy?->name ?? '—'),

                DateTimePicker::make('resolved_at')
                    ->label('Resolved At')
                    ->disabled(),
            ]);
    }
}
