<?php

namespace App\Filament\Resources\AppointmentRequests\Tables;

use App\Actions\Appointments\LinkAppointmentRequestToPatient;
use App\Actions\Appointments\RejectAppointmentRequest;
use App\Actions\PatientAccounts\RankPatientCandidates;
use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Filament\Support\AppointmentRequestTimeAvailability;
use App\Models\AppointmentRequest;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppointmentRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_number')
                    ->label('Request #')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('patient_id')
                    ->label('')
                    ->icon(fn (AppointmentRequest $record): string => $record->patient_id === null
                        ? 'heroicon-o-exclamation-triangle'
                        : 'heroicon-o-check-circle')
                    ->color(fn (AppointmentRequest $record): string => $record->patient_id === null ? 'warning' : 'success')
                    ->tooltip(fn (AppointmentRequest $record): string => $record->patient_id === null
                        ? 'Not yet linked to a patient record'
                        : 'Linked to a patient record'),

                TextColumn::make('patient.full_name')
                    ->weight('bold')
                    ->label('Patient')
                    ->default(fn (AppointmentRequest $record): string => $record->patient?->full_name ?? $record->user?->full_name ?? '—')
                    ->searchable(['patient.first_name', 'patient.last_name', 'user.first_name', 'user.last_name']),

                TextColumn::make('appointment_type')
                    ->label('Type')
                    ->state(fn (AppointmentRequest $record): string => $record->appointmentType?->patient_label ?? '—'),

                TextColumn::make('encrypted_referring_source')
                    ->label('Referral')
                    ->placeholder('Not requested')
                    ->color(fn (?string $state): ?string => $state === null ? 'gray' : null),

                ViewColumn::make('scheduled_at')
                    ->label('Preferred Times')
                    ->view('filament.tables.columns.appointment-request-preferred-times')
                    ->state(fn (AppointmentRequest $record): array => AppointmentRequestTimeAvailability::forTable($record))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('M j, g:i A')
                    ->sortable(),

                TextColumn::make('status')
                    ->state(fn (AppointmentRequest $record): AppointmentRequestStatus => $record->effectiveStatus())
                    ->badge()
                    ->color(fn (AppointmentRequestStatus $state) => match ($state) {
                        AppointmentRequestStatus::Pending => 'warning',
                        AppointmentRequestStatus::Accepted => 'success',
                        AppointmentRequestStatus::Rejected => 'danger',
                        AppointmentRequestStatus::Cancelled => 'gray',
                        AppointmentRequestStatus::Expired => 'gray',
                    })
                    ->formatStateUsing(fn (AppointmentRequestStatus $state): string => Str::headline($state->value)),
            ])
            ->defaultSort(function (Builder $query): Builder {
                $table = $query->getModel()->getTable();
                $pendingStatus = AppointmentRequestStatus::Pending->value;
                $now = now();

                return $query
                    ->orderByRaw(
                        "CASE WHEN {$table}.status = ? AND {$table}.expires_at > ? THEN 0 WHEN {$table}.status = ? AND {$table}.expires_at <= ? THEN 1 ELSE 2 END",
                        [$pendingStatus, $now, $pendingStatus, $now],
                    )
                    ->orderBy('expires_at', 'asc')
                    ->orderBy('created_at', 'desc');
            })
            ->filters([
                SelectFilter::make('status')
                    ->options(AppointmentRequestStatus::class)
                    ->query(function (Builder $query, array $data): void {
                        $status = $data['value'] ?? null;

                        if (blank($status)) {
                            return;
                        }

                        if ($status === AppointmentRequestStatus::Pending->value) {
                            $query
                                ->where('status', AppointmentRequestStatus::Pending)
                                ->where('expires_at', '>', now());

                            return;
                        }

                        if ($status === AppointmentRequestStatus::Expired->value) {
                            $query->where(function (Builder $query): void {
                                $query
                                    ->where('status', AppointmentRequestStatus::Expired)
                                    ->orWhere(function (Builder $query): void {
                                        $query
                                            ->where('status', AppointmentRequestStatus::Pending)
                                            ->where('expires_at', '<=', now());
                                    });
                            });

                            return;
                        }

                        $query->where('status', $status);
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('view')
                        ->label('View request')
                        ->icon('heroicon-o-eye')
                        ->url(fn (AppointmentRequest $record) => AppointmentRequestResource::getUrl('view', ['record' => $record])),

                    Action::make('reviewSchedule')
                        ->label('Review & Schedule')
                        ->icon('heroicon-o-calendar-days')
                        ->color('success')
                        ->visible(fn (AppointmentRequest $record): bool => $record->isReadyForScheduleReview())
                        ->authorize('accept')
                        ->url(fn (AppointmentRequest $record): string => AppointmentRequestResource::getUrl('schedule', ['record' => $record])),

                    Action::make('linkToPatient')
                        ->label('Link to Patient')
                        ->icon('heroicon-o-link')
                        ->color('primary')
                        ->visible(fn (AppointmentRequest $record): bool => $record->isPending() && $record->patient_id === null)
                        ->authorize('link')
                        ->schema(function (AppointmentRequest $record): array {
                            $candidateOptions = [];

                            if ($record->hasIdentitySnapshot()) {
                                $candidateOptions = app(RankPatientCandidates::class)
                                    ->fromSnapshot($record->encrypted_identity_snapshot)
                                    ->mapWithKeys(fn (array $candidate): array => [
                                        $candidate['patient']->id => "{$candidate['patient']->full_name} ({$candidate['patient']->patient_number}) — ".Str::headline($candidate['strength']).' match',
                                    ])
                                    ->toArray();
                            }

                            $otherOptions = Patient::query()
                                ->whereNull('user_id')
                                ->whereNotIn('id', array_keys($candidateOptions))
                                ->get()
                                ->mapWithKeys(fn (Patient $p): array => [
                                    $p->id => "{$p->full_name} ({$p->patient_number})",
                                ])
                                ->toArray();

                            return [
                                Select::make('patient_id')
                                    ->label('Patient')
                                    ->options(array_filter([
                                        'Candidate Matches' => $candidateOptions,
                                        'All Patients' => $otherOptions,
                                    ]))
                                    ->searchable()
                                    ->required()
                                    ->helperText('Select which clinical record this request belongs to.'),
                            ];
                        })
                        ->action(function (AppointmentRequest $record, array $data): void {
                            try {
                                $patient = Patient::findOrFail($data['patient_id']);

                                app(LinkAppointmentRequestToPatient::class)->handle(
                                    request: $record,
                                    patient: $patient,
                                );

                                Notification::make()->title('Request linked to patient')->success()->send();
                            } catch (ValidationException $e) {
                                $message = collect($e->errors())->flatten()->first() ?? 'Cannot link request.';
                                Notification::make()->title('Cannot link request')->body($message)->danger()->send();
                            }
                        }),

                    Action::make('reject')
                        ->label('Reject')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (AppointmentRequest $record): bool => $record->isPending())
                        ->authorize('reject')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Reason')
                                ->required(),
                        ])
                        ->requiresConfirmation()
                        ->action(function (AppointmentRequest $record, array $data): void {
                            try {
                                app(RejectAppointmentRequest::class)->handle(
                                    request: $record,
                                    reviewer: auth()->user(),
                                    reason: $data['reason'],
                                );

                                Notification::make()->title('Request rejected')->success()->send();
                            } catch (ValidationException $e) {
                                $message = collect($e->errors())->flatten()->first() ?? 'Cannot reject.';
                                Notification::make()->title('Cannot reject')->body($message)->danger()->send();
                            }
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
