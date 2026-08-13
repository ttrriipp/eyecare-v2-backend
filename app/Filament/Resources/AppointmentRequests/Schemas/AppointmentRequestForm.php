<?php

namespace App\Filament\Resources\AppointmentRequests\Schemas;

use App\Actions\PatientAccounts\RankPatientCandidates;
use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\PatientCandidateMatchCard;
use Filament\Forms\Components\Placeholder;
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
                // ── 1. Patient Information ──────────────────────────────────
                // Who this request is about comes first: for an unlinked
                // request, that identity decision is the reason staff opened
                // this page at all, and it should not sit below scheduling
                // admin they can't act on until it's resolved.
                //
                // Every top-level section spans the full width and stacks in
                // this order. Without an explicit span, Filament lays them
                // out in an alternating two-column grid, which pairs
                // unrelated sections by coincidence of position (a one-line
                // section next to the densest one) rather than by meaning.
                Section::make('Patient Information')
                    ->visible(fn ($record): bool => $record?->patient_id !== null)
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('linked_patient_name')
                            ->label('Linked Patient')
                            ->content(function ($record): HtmlString {
                                if ($record?->patient === null) {
                                    return new HtmlString('—');
                                }

                                $url = PatientResource::getUrl('edit', ['record' => $record->patient]);

                                return new HtmlString(
                                    '<a href="'.e($url).'" class="text-primary-600 hover:underline dark:text-primary-400">'
                                    .e($record->patient->full_name)
                                    .'</a>'
                                );
                            }),
                    ]),

                Section::make('Patient Information')
                    ->visible(fn ($record): bool => ($record?->hasIdentitySnapshot() ?? false) && $record?->patient_id === null)
                    ->columnSpanFull()
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

                // ── 2. Potential Matches ────────────────────────────────────
                // Directly below identity, since it's the decision aid for
                // the Link-to-Patient action — not a footnote after logistics.
                Section::make('Potential Matches')
                    ->visible(fn ($record): bool => ($record?->hasIdentitySnapshot() ?? false) && $record?->patient_id === null)
                    ->columnSpanFull()
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

                // ── 3. Request Summary ──────────────────────────────────────
                Section::make('Request Summary')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('account_status')
                            ->label('Account Status')
                            ->content(fn ($record): string => $record?->patient_id !== null ? 'Linked' : 'Unlinked')
                            ->badge()
                            ->color(fn ($record): string => $record?->patient_id !== null ? 'success' : 'warning'),

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

                        Placeholder::make('reason_for_visit')
                            ->label('Reason for Visit')
                            ->content(fn ($record): string => $record?->encrypted_reason_for_visit ?? '—')
                            ->columnSpanFull(),

                        Placeholder::make('appointment_type')
                            ->label('Appointment Type')
                            ->content(fn ($record): string => $record?->appointmentType?->name ?? '—'),

                        Placeholder::make('provisional_duration')
                            ->label('Provisional Duration')
                            ->content(fn ($record): string => $record?->provisional_duration_minutes !== null
                                ? "{$record->provisional_duration_minutes} minutes"
                                : '—'),

                        Placeholder::make('submitted_at')
                            ->label('Submitted')
                            ->content(function ($record): string {
                                if ($record === null) {
                                    return '—';
                                }

                                $snapshot = $record->encrypted_identity_snapshot;
                                $submittedAt = (isset($snapshot['submitted_at']))
                                    ? Carbon::parse($snapshot['submitted_at'])
                                    : $record->created_at;

                                return $submittedAt?->format('M j, Y \a\t g:i A') ?? '—';
                            }),
                    ])
                    ->columns(3),

                // ── 4. Requested Schedule ───────────────────────────────────
                Section::make('Requested Schedule')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('submitted_times')
                            ->label('Preferred Time')
                            ->content(function ($record): HtmlString {
                                if ($record === null) {
                                    return new HtmlString('—');
                                }

                                $badges = [];

                                $badges[] = '<span class="inline-flex items-center gap-1 rounded-md bg-primary-50 px-2 py-1 text-xs font-medium text-primary-700 dark:bg-primary-500/15 dark:text-primary-400">'
                                    .'<span class="font-semibold">Primary</span> '
                                    .e($record->scheduled_at?->format('M j, g:i A') ?? '—')
                                    .'</span>';

                                if (! empty($record->alternative_scheduled_times)) {
                                    foreach ($record->alternative_scheduled_times as $index => $time) {
                                        $badges[] = '<span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-400">'
                                            .'<span class="font-semibold">Alt '.($index + 1).'</span> '
                                            .e(Carbon::parse($time)->format('M j, g:i A'))
                                            .'</span>';
                                    }
                                }

                                return new HtmlString(
                                    '<div class="flex flex-wrap gap-2">'.implode('', $badges).'</div>'
                                );
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(1),

                // ── 5. Referral Source ──────────────────────────────────────
                Section::make('Referral')
                    ->visible(fn ($record): bool => ! empty($record?->encrypted_referring_source))
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('referral_context')
                            ->label('Referral Source')
                            ->content(fn ($record): string => $record?->encrypted_referring_source ?? '—'),
                    ]),

                // ── 6. Decision Details ─────────────────────────────────────
                Section::make('Decision Details')
                    ->visible(fn ($record): bool => $record?->status !== AppointmentRequestStatus::Pending)
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('resolved_by')
                            ->label('Resolved By')
                            ->content(fn ($record): string => $record?->resolvedBy?->full_name ?? '—')
                            ->visible(fn ($record): bool => $record?->resolvedBy !== null),

                        Placeholder::make('resolved_at')
                            ->label('Resolved At')
                            ->content(fn ($record): string => $record?->resolved_at?->format('M j, Y g:i A') ?? '—')
                            ->visible(fn ($record): bool => $record?->resolved_at !== null),

                        Placeholder::make('resulting_appointment')
                            ->label('Appointment')
                            ->content(function ($record): HtmlString {
                                $appointment = $record?->appointment;

                                if ($appointment === null) {
                                    return new HtmlString('—');
                                }

                                $url = AppointmentResource::getUrl('edit', ['record' => $appointment]);

                                return new HtmlString(
                                    '<a href="'.e($url).'" class="text-primary-600 hover:underline dark:text-primary-400">'
                                    .e($appointment->appointment_number)
                                    .'</a>'
                                );
                            })
                            ->visible(fn ($record): bool => $record?->appointment_id !== null),

                        Placeholder::make('rejection_reason')
                            ->label('Rejection Reason')
                            ->content(fn ($record): string => $record?->rejection_reason ?? '—')
                            ->visible(fn ($record): bool => $record?->status === AppointmentRequestStatus::Rejected && ! empty($record?->rejection_reason))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
