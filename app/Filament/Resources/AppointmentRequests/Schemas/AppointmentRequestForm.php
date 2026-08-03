<?php

namespace App\Filament\Resources\AppointmentRequests\Schemas;

use App\Actions\PatientAccounts\RankPatientCandidates;
use App\Enums\AppointmentRequestStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

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
                    ->content(fn ($record): string => $record?->user?->full_name ?? '—'),

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
                    ->content(fn ($record): string => $record?->resolvedBy?->full_name ?? '—'),

                DateTimePicker::make('resolved_at')
                    ->label('Resolved At')
                    ->disabled(),

                // Submitted identity section - only for unlinked snapshotted requests
                Section::make('Submitted Identity')
                    ->visible(fn ($record): bool => $record?->hasIdentitySnapshot() ?? false)
                    ->schema([
                        Placeholder::make('snapshot_name')
                            ->label('Name')
                            ->content(fn ($record): string => $record?->getSnapshotDisplayName() ?? '—'),

                        Placeholder::make('snapshot_dob')
                            ->label('Date of Birth')
                            ->content(fn ($record): string => $record?->getSnapshotDateOfBirth() ?? '—'),

                        Placeholder::make('snapshot_phone')
                            ->label('Phone')
                            ->content(fn ($record): string => $record?->getSnapshotMaskedPhone() ?? '—'),

                        Placeholder::make('snapshot_email')
                            ->label('Email')
                            ->content(fn ($record): string => $record?->getSnapshotMaskedEmail() ?? 'Not provided'),

                        Placeholder::make('snapshot_gender')
                            ->label('Gender')
                            ->content(fn ($record): string => $record?->getSnapshotGender() ?? '—'),

                        Placeholder::make('snapshot_occupation')
                            ->label('Occupation')
                            ->content(fn ($record): string => $record?->getSnapshotOccupation() ?? '—'),

                        Placeholder::make('snapshot_address')
                            ->label('Home Address')
                            ->content(fn ($record): string => $record?->getSnapshotAddress() ?? '—')
                            ->columnSpanFull(),

                        Placeholder::make('snapshot_contact_type')
                            ->label('Contact Type')
                            ->content(fn ($record): string => match ($record?->getSnapshotContactType()) {
                                'phone' => 'Phone',
                                'email' => 'Email',
                                default => '—',
                            }),

                        Placeholder::make('snapshot_contact')
                            ->label('Verified Contact')
                            ->content(fn ($record): string => $record?->getSnapshotMaskedContact() ?? '—'),

                        Placeholder::make('snapshot_submitted_at')
                            ->label('Submitted')
                            ->content(function ($record): string {
                                $snapshot = $record?->encrypted_identity_snapshot;
                                if ($snapshot === null || ! isset($snapshot['submitted_at'])) {
                                    return '—';
                                }

                                return Carbon::parse($snapshot['submitted_at'])->format('M j, Y g:i A');
                            }),
                    ])
                    ->columns(2),

                // Candidate matches section - only for unlinked snapshotted requests
                Section::make('Candidate Matches')
                    ->visible(fn ($record): bool => $record?->hasIdentitySnapshot() ?? false)
                    ->schema([
                        Placeholder::make('candidates')
                            ->label('')
                            ->content(function ($record): string {
                                if ($record === null || ! $record->hasIdentitySnapshot()) {
                                    return 'No candidates';
                                }

                                $candidates = app(RankPatientCandidates::class)
                                    ->fromSnapshot($record->encrypted_identity_snapshot);

                                if ($candidates->isEmpty()) {
                                    return 'No matching patients found';
                                }

                                return $candidates->map(function ($c) {
                                    $strength = ucfirst($c['strength']);
                                    $reasons = implode(', ', $c['reasons']);
                                    $name = $c['patient']->full_name;
                                    $dob = $c['patient']->date_of_birth?->format('M j, Y') ?? '—';

                                    return "{$name} (DOB: {$dob}) — {$strength} match [{$reasons}]";
                                })->implode("\n");
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
