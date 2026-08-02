<?php

namespace App\Filament\Resources\PatientAccounts\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

class PatientAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
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
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Link Status')->schema([
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
                ]),
            ]),
        ]);
    }
}
