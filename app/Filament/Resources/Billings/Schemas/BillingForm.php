<?php

namespace App\Filament\Resources\Billings\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class BillingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('due_date')
                    ->label('Due Date')
                    ->nullable(),
                Textarea::make('notes')
                    ->label('Notes')
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }
}
