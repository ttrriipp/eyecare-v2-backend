<?php

namespace App\Filament\Resources\AppointmentRequests\Schemas;

use App\Actions\PatientAccounts\RankPatientCandidates;
use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\Patients\PatientResource;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class AppointmentRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request Details')
                    ->schema([
                        Placeholder::make('request_number')
                            ->label('Request #')
                            ->content(fn ($record): string => $record?->request_number ?? '—'),

                        Placeholder::make('account_owner')
                            ->label('Account Owner')
                            ->content(fn ($record): string => $record?->user?->full_name ?? '—'),

                        Placeholder::make('patient_name')
                            ->label('Patient')
                            ->content(fn ($record) => $record?->patient?->full_name ?? '—'),

                        Placeholder::make('preferred_time')
                            ->label('Preferred Time')
                            ->content(fn ($record) => $record?->scheduled_at?->format('M j, Y g:i A') ?? '—'),

                        Textarea::make('encrypted_reason_for_visit')
                            ->label('Reason for Visit')
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Status')
                    ->schema([
                        Placeholder::make('status')
                            ->label('Status')
                            ->content(fn ($record): string => Str::headline($record?->status->value ?? '—')),

                        Placeholder::make('expires_at')
                            ->label('Expires')
                            ->content(fn ($record): string => $record?->expires_at?->format('M j, Y g:i A') ?? '—'),

                        Placeholder::make('resolved_by')
                            ->label('Resolved By')
                            ->content(fn ($record): string => $record?->resolvedBy?->full_name ?? '—')
                            ->visible(fn ($record): bool => $record?->status !== AppointmentRequestStatus::Pending),

                        Placeholder::make('resolved_at')
                            ->label('Resolved At')
                            ->content(fn ($record): string => $record?->resolved_at?->format('M j, Y g:i A') ?? '—')
                            ->visible(fn ($record): bool => $record?->status !== AppointmentRequestStatus::Pending),

                        Placeholder::make('submitted_at')
                            ->label('Submitted')
                            ->content(function ($record): string {
                                $snapshot = $record?->encrypted_identity_snapshot;
                                if ($snapshot === null || ! isset($snapshot['submitted_at'])) {
                                    return $record?->created_at?->format('M j, Y g:i A') ?? '—';
                                }

                                return Carbon::parse($snapshot['submitted_at'])->format('M j, Y g:i A');
                            }),
                    ])
                    ->columns(2),

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
                            ->content(fn ($record): string => $record?->getSnapshotPhone() ?? '—'),

                        Placeholder::make('snapshot_email')
                            ->label('Email')
                            ->content(fn ($record): string => $record?->getSnapshotEmail() ?? 'Not provided'),

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
                    ])
                    ->columns(2),

                // Candidate matches section - only for unlinked snapshotted requests
                Section::make('Candidate Matches')
                    ->description('Ranked automatically from the submitted identity. Use the "Link to Patient" action to resolve.')
                    ->visible(fn ($record): bool => ($record?->hasIdentitySnapshot() ?? false) && $record?->patient_id === null)
                    ->schema([
                        Placeholder::make('candidates')
                            ->hiddenLabel()
                            ->content(function ($record): HtmlString {
                                if ($record === null || ! $record->hasIdentitySnapshot()) {
                                    return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">No candidates.</span>');
                                }

                                $candidates = app(RankPatientCandidates::class)
                                    ->fromSnapshot($record->encrypted_identity_snapshot);

                                if ($candidates->isEmpty()) {
                                    return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">No matching patients found.</span>');
                                }

                                $colors = [
                                    'strong' => 'text-success-700 bg-success-50 dark:text-success-400 dark:bg-success-500/10',
                                    'moderate' => 'text-warning-700 bg-warning-50 dark:text-warning-400 dark:bg-warning-500/10',
                                    'weak' => 'text-gray-600 bg-gray-100 dark:text-gray-400 dark:bg-gray-500/10',
                                ];

                                $rows = $candidates->map(function (array $candidate) use ($colors): string {
                                    $patient = $candidate['patient'];
                                    $color = $colors[$candidate['strength']] ?? $colors['weak'];
                                    $reasons = collect($candidate['reasons'])
                                        ->map(fn (string $reason): string => Str::headline($reason))
                                        ->implode(', ');
                                    $url = PatientResource::getUrl('edit', ['record' => $patient]);

                                    return '<li class="mb-2">'
                                        .'<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium '.$color.'">'
                                        .e(Str::headline($candidate['strength'])).'</span> '
                                        .'<a href="'.e($url).'" target="_blank" class="font-medium text-primary-600 hover:underline dark:text-primary-400">'
                                        .e($patient->full_name).'</a> — '.e($patient->patient_number)
                                        .($reasons !== '' ? '<div class="text-xs text-gray-500 dark:text-gray-400">'.e($reasons).'</div>' : '')
                                        .'</li>';
                                })->implode('');

                                return new HtmlString('<ul class="list-none space-y-1 text-sm">'.$rows.'</ul>');
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
