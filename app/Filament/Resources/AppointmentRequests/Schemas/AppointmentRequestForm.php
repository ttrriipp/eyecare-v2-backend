<?php

namespace App\Filament\Resources\AppointmentRequests\Schemas;

use App\Actions\PatientAccounts\RankPatientCandidates;
use App\Enums\AppointmentRequestStatus;
use App\Filament\Support\PatientCandidateMatchCard;
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

                        Placeholder::make('account_status')
                            ->label('Account Status')
                            ->content(fn ($record): string => $record?->patient_id !== null ? 'Linked' : 'Unlinked')
                            ->badge()
                            ->color(fn ($record): string => $record?->patient_id !== null ? 'success' : 'warning'),

                        Placeholder::make('preferred_time')
                            ->label('Preferred Time')
                            ->content(fn ($record) => $record?->scheduled_at?->format('M j, Y g:i A') ?? '—'),

                        Placeholder::make('appointment_type')
                            ->label('Patient Appointment Type')
                            ->content(fn ($record): string => $record?->appointmentType?->patient_label ?? '—'),

                        Placeholder::make('internal_appointment_type')
                            ->label('Internal Appointment Type')
                            ->content(fn ($record): string => $record?->appointmentType?->name ?? '—'),

                        Placeholder::make('provisional_duration')
                            ->label('Provisional Duration')
                            ->content(fn ($record): string => $record?->provisional_duration_minutes !== null
                                ? "{$record->provisional_duration_minutes} minutes"
                                : '—'),

                        Placeholder::make('referral_context')
                            ->label('Referral Context')
                            ->content(fn ($record): string => $record?->encrypted_referring_source ?? 'None'),

                        Placeholder::make('alternative_preferences')
                            ->label('Submitted Time Preferences')
                            ->content(function ($record): HtmlString {
                                if ($record === null) {
                                    return new HtmlString('—');
                                }

                                $rows = [];

                                // Primary time
                                $rows[] = '<tr>'
                                    .'<td class="px-2 py-1 text-xs font-medium text-gray-500 dark:text-gray-400">Primary</td>'
                                    .'<td class="px-2 py-1 text-sm font-medium text-gray-800 dark:text-gray-100">'
                                    .e($record->scheduled_at?->format('M j, Y g:i A') ?? '—')
                                    .'</td></tr>';

                                // Alternative times
                                if (! empty($record->alternative_scheduled_times)) {
                                    foreach ($record->alternative_scheduled_times as $index => $time) {
                                        $rows[] = '<tr>'
                                            .'<td class="px-2 py-1 text-xs text-gray-500 dark:text-gray-400">Alt '.($index + 1).'</td>'
                                            .'<td class="px-2 py-1 text-sm text-gray-700 dark:text-gray-200">'
                                            .e(Carbon::parse($time)->format('M j, Y g:i A'))
                                            .'</td></tr>';
                                    }
                                }

                                return new HtmlString(
                                    '<table class="text-sm"><tbody>'.implode('', $rows).'</tbody></table>'
                                );
                            })
                            ->columnSpanFull(),

                        Textarea::make('encrypted_reason_for_visit')
                            ->label('Reason for Visit')
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Status')
                    ->schema([
                        Placeholder::make('status')
                            ->label('Request Status')
                            ->content(fn ($record): string => Str::headline($record?->status->value ?? '—'))
                            ->badge()
                            ->color(fn ($record): string => match ($record?->status) {
                                AppointmentRequestStatus::Pending => 'warning',
                                AppointmentRequestStatus::Accepted => 'success',
                                AppointmentRequestStatus::Rejected => 'danger',
                                AppointmentRequestStatus::Cancelled => 'gray',
                                AppointmentRequestStatus::Expired => 'gray',
                                default => 'gray',
                            }),

                        Placeholder::make('expires_at')
                            ->label('Expires')
                            ->content(fn ($record): string => $record?->expires_at?->format('M j, Y g:i A') ?? '—'),

                        Placeholder::make('request_age')
                            ->label('Request Age')
                            ->content(fn ($record): string => $record?->created_at?->diffForHumans() ?? '—'),

                        Placeholder::make('overdue')
                            ->label('Overdue')
                            ->content(fn ($record): string => $record?->status === AppointmentRequestStatus::Pending
                                && $record?->expires_at?->isPast() ? 'Yes' : 'No')
                            ->badge()
                            ->color(fn ($record): string => $record?->status === AppointmentRequestStatus::Pending
                                && $record?->expires_at?->isPast() ? 'danger' : 'gray'),

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

                        Placeholder::make('rejection_reason')
                            ->label('Rejection Reason')
                            ->content(fn ($record): string => $record?->rejection_reason ?? '—')
                            ->visible(fn ($record): bool => $record?->status === AppointmentRequestStatus::Rejected)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                // Patient information section - only for unlinked snapshotted requests
                Section::make('Patient Information')
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
                            ->content(fn ($record): string => Str::headline($record?->getSnapshotGender() ?? '—')),

                        Placeholder::make('snapshot_occupation')
                            ->label('Occupation')
                            ->content(fn ($record): string => $record?->getSnapshotOccupation() ?? '—'),

                        Placeholder::make('snapshot_address')
                            ->label('Home Address')
                            ->content(fn ($record): string => $record?->getSnapshotAddress() ?? '—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                // Potential matches section - only for unlinked snapshotted requests
                Section::make('Potential Matches')
                    ->visible(fn ($record): bool => ($record?->hasIdentitySnapshot() ?? false) && $record?->patient_id === null)
                    ->schema([
                        Placeholder::make('candidates')
                            ->hiddenLabel()
                            ->content(function ($record): HtmlString {
                                if ($record === null || ! $record->hasIdentitySnapshot()) {
                                    return PatientCandidateMatchCard::render(collect(), 'No candidates.');
                                }

                                $candidates = app(RankPatientCandidates::class)
                                    ->fromSnapshot($record->encrypted_identity_snapshot);

                                return PatientCandidateMatchCard::render($candidates);
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
