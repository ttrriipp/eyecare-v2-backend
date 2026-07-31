<?php

namespace App\Filament\Resources\PatientLinkRequests\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class PatientLinkRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('request_number')
                    ->label('Request #')
                    ->content(fn ($record) => $record?->request_number ?? '—'),

                Placeholder::make('account_owner')
                    ->label('Account Owner')
                    ->content(fn ($record) => $record?->user?->name ?? '—'),

                Placeholder::make('account_email')
                    ->label('Account Email')
                    ->content(fn ($record) => $record?->user?->email ?? '—'),

                Placeholder::make('status')
                    ->label('Status')
                    ->content(fn ($record) => $record?->status ?? '—'),

                Placeholder::make('linked_patient')
                    ->label('Linked Patient')
                    ->content(fn ($record) => $record?->reviewedPatient?->full_name ?? '—'),

                Placeholder::make('reviewer')
                    ->label('Reviewed By')
                    ->content(fn ($record) => $record?->reviewer?->name ?? '—'),

                Textarea::make('decision_note')
                    ->label('Decision Note')
                    ->disabled()
                    ->columnSpanFull(),
            ]);
    }
}
