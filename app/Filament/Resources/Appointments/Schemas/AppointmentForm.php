<?php

namespace App\Filament\Resources\Appointments\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class AppointmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->disabled()
                    ->dehydrated(),
                Select::make('visit_reason_id')
                    ->relationship('visitReason', 'name')
                    ->disabled()
                    ->dehydrated(),
                Select::make('appointment_status_id')
                    ->relationship('status', 'name')
                    ->required()
                    ->live(),
                DateTimePicker::make('scheduled_at')
                    ->required(),
                Textarea::make('contact_notes')
                    ->disabled()
                    ->dehydrated()
                    ->columnSpanFull(),
                Textarea::make('staff_notes')
                    ->columnSpanFull(),
            ]);
    }
}
