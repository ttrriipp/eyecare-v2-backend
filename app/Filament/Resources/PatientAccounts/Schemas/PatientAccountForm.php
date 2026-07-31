<?php

namespace App\Filament\Resources\PatientAccounts\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

class PatientAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Account Details')->columns(2)->schema([
                Placeholder::make('name')
                    ->label('Account Name')
                    ->content(fn ($record) => $record?->name ?? '—'),

                Placeholder::make('email')
                    ->label('Email')
                    ->content(fn ($record) => $record?->email ?? '—'),

                Placeholder::make('phone')
                    ->label('Phone')
                    ->content(fn ($record) => $record?->phone ?? '—'),

                Placeholder::make('created_at')
                    ->label('Registered')
                    ->content(fn ($record) => $record?->created_at?->format('M j, Y g:i A') ?? '—'),
            ]),

            Section::make('Link Status')->columns(2)->schema([
                Placeholder::make('link_status')
                    ->label('Status')
                    ->content(function ($record): string {
                        if ($record === null) {
                            return '—';
                        }
                        if ($record->patient !== null) {
                            return 'Linked';
                        }
                        if ($record->linkRequests()->where('status', 'pending')->exists()) {
                            return 'Pending Review';
                        }

                        return 'Unlinked';
                    })
                    ->badge()
                    ->color(function ($record): string {
                        if ($record === null) {
                            return 'gray';
                        }
                        if ($record->patient !== null) {
                            return 'success';
                        }
                        if ($record->linkRequests()->where('status', 'pending')->exists()) {
                            return 'warning';
                        }

                        return 'gray';
                    })
                    ->size(TextSize::Large),

                Placeholder::make('linked_patient')
                    ->label('Linked Patient')
                    ->content(fn ($record) => $record?->patient?->full_name ?? '—'),

                Placeholder::make('patient_number')
                    ->label('Patient Number')
                    ->content(fn ($record) => $record?->patient?->patient_number ?? '—'),
            ]),

            Section::make('Verified Contacts')->schema([
                Placeholder::make('verified_contacts')
                    ->label('Contacts')
                    ->content(function ($record): string {
                        if ($record === null) {
                            return '—';
                        }

                        $contacts = $record->contacts()->whereNotNull('verified_at')->get();

                        if ($contacts->isEmpty()) {
                            return 'No verified contacts';
                        }

                        return $contacts->map(fn ($c) => strtoupper($c->type).': '.$c->encrypted_value.($c->is_primary ? ' (Primary)' : '')
                        )->implode("\n");
                    })
                    ->columnSpanFull(),
            ]),
        ]);
    }
}
