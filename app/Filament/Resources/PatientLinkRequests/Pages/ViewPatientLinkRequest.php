<?php

namespace App\Filament\Resources\PatientLinkRequests\Pages;

use App\Actions\PatientAccounts\ReviewPatientLinkRequest;
use App\Filament\Resources\PatientLinkRequests\PatientLinkRequestResource;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewPatientLinkRequest extends ViewRecord
{
    protected static string $resource = PatientLinkRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === 'pending')
                ->schema([
                    Select::make('patient_id')
                        ->label('Link to Patient')
                        ->options(function (): array {
                            $candidates = $this->record->candidates()->with('patient')->orderBy('rank')->get()
                                ->filter(fn ($candidate) => $candidate->patient !== null && $candidate->patient->user_id === null);

                            $candidateOptions = $candidates->mapWithKeys(fn ($candidate) => [
                                $candidate->patient_id => "{$candidate->patient->full_name} ({$candidate->patient->patient_number}) — ".Str::headline($candidate->match_strength).' match',
                            ])->toArray();

                            $otherOptions = Patient::whereNull('user_id')
                                ->whereNotIn('id', $candidates->pluck('patient_id'))
                                ->get()
                                ->mapWithKeys(fn ($p) => [
                                    $p->id => "{$p->full_name} ({$p->patient_number})",
                                ])
                                ->toArray();

                            return array_filter([
                                'Candidate Matches' => $candidateOptions,
                                'Other Unlinked Patients' => $otherOptions,
                            ]);
                        })
                        ->searchable()
                        ->live()
                        ->required()
                        ->helperText('Select the patient to link this account to.'),
                    Textarea::make('decision_note')
                        ->label('Decision Note')
                        ->required(function (Get $get): bool {
                            $patientId = $get('patient_id');

                            if (blank($patientId)) {
                                return false;
                            }

                            $isStrongMatch = $this->record->candidates()
                                ->where('patient_id', $patientId)
                                ->where('match_strength', 'strong')
                                ->exists();

                            return ! $isStrongMatch;
                        })
                        ->helperText(function (Get $get): ?string {
                            $patientId = $get('patient_id');

                            if (blank($patientId)) {
                                return null;
                            }

                            $isStrongMatch = $this->record->candidates()
                                ->where('patient_id', $patientId)
                                ->where('match_strength', 'strong')
                                ->exists();

                            return $isStrongMatch
                                ? null
                                : 'This is not a strong match — explain why you\'re confident this is the right patient.';
                        }),
                ])
                ->action(function (array $data): void {
                    try {
                        $patient = Patient::findOrFail($data['patient_id']);

                        app(ReviewPatientLinkRequest::class)->approve(
                            linkRequest: $this->record,
                            patient: $patient,
                            reviewer: auth()->user(),
                            note: $data['decision_note'] ?? null,
                        );

                        $this->record->refresh();
                        Notification::make()
                            ->title('Link request approved')
                            ->success()
                            ->send();
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot approve.';
                        Notification::make()
                            ->title('Cannot approve')
                            ->body($message)
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->status === 'pending')
                ->schema([
                    Textarea::make('note')
                        ->label('Rejection Reason')
                        ->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    try {
                        app(ReviewPatientLinkRequest::class)->reject(
                            linkRequest: $this->record,
                            reviewer: auth()->user(),
                            note: $data['note'],
                        );

                        $this->record->refresh();
                        Notification::make()
                            ->title('Link request rejected')
                            ->success()
                            ->send();
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot reject.';
                        Notification::make()
                            ->title('Cannot reject')
                            ->body($message)
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
