<?php

namespace App\Filament\Resources\PatientLinkRequests\Schemas;

use App\Models\PatientLinkRequest;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PatientLinkRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        $accountInformation = Section::make('Account Information')
            ->schema([
                Placeholder::make('account_owner')
                    ->label('Account Owner')
                    ->content(fn ($record): string => $record?->user?->full_name ?? '—'),

                Placeholder::make('account_email')
                    ->label('Email')
                    ->content(fn ($record) => $record?->user?->email ?? '—'),

                Placeholder::make('account_phone')
                    ->label('Phone')
                    ->content(fn ($record) => $record?->user?->phone ?? '—'),

                Placeholder::make('submitted_dob')
                    ->label('Date of Birth')
                    ->content(function ($record): string {
                        if ($record === null) {
                            return '—';
                        }

                        $snapshot = $record->encrypted_identity_snapshot ?? [];

                        return $snapshot['date_of_birth'] ?? '—';
                    }),
            ])
            ->columns(2);

        $requestSummary = Section::make('Request Summary')
            ->schema([
                Placeholder::make('request_number')
                    ->label('Request #')
                    ->content(fn ($record) => $record?->request_number ?? '—'),

                Placeholder::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($record) => match ($record?->status) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->content(fn ($record) => $record ? Str::headline($record->status) : '—'),

                Placeholder::make('request_age')
                    ->label('Submitted')
                    ->content(fn ($record): string => $record?->created_at?->diffForHumans() ?? '—'),

                Placeholder::make('expiry_reason')
                    ->label('Expiry Reason')
                    ->content(fn (?PatientLinkRequest $record): string => $record?->expiryReasonLabel() ?? '—')
                    ->visible(fn (?PatientLinkRequest $record): bool => $record?->isExpired() ?? false)
                    ->columnSpanFull(),
            ])
            ->columns(3);

        $decisionDetails = Section::make('Decision Details')
            ->schema([
                Placeholder::make('linked_patient')
                    ->label('Linked Patient')
                    ->content(fn ($record) => $record?->reviewedPatient?->full_name ?? '—')
                    ->weight('bold'),

                Placeholder::make('reviewer')
                    ->label('Reviewed By')
                    ->content(fn ($record): string => $record?->reviewer?->full_name ?? '—'),

                Placeholder::make('reviewed_at')
                    ->label('Reviewed At')
                    ->content(fn ($record): string => $record?->reviewed_at?->format('M j, Y g:i A') ?? '—'),

                Textarea::make('decision_note')
                    ->label('Decision Note')
                    ->disabled()
                    ->columnSpanFull(),
            ])
            ->columns(2)
            ->visible(fn ($record) => $record !== null && $record->status !== 'pending');

        return $schema->columns(1)->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Grid::make(1)
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->schema([
                        $requestSummary,
                    ]),

                Grid::make(1)
                    ->columnSpan(['default' => 1, 'lg' => 1])
                    ->schema([
                        $accountInformation,
                        $decisionDetails,
                    ]),
            ]),
        ]);
    }
}
