<?php

namespace App\Filament\Resources\AppointmentRescheduleRequests\Pages;

use App\Actions\Appointments\ApproveAppointmentRescheduleRequest;
use App\Actions\Appointments\EvaluateAppointmentAvailability;
use App\Actions\Appointments\RejectAppointmentRescheduleRequest;
use App\Exceptions\AppointmentRescheduleRequestStateException;
use App\Filament\Resources\AppointmentRescheduleRequests\AppointmentRescheduleRequestResource;
use App\Models\AppointmentRescheduleRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ViewAppointmentRescheduleRequest extends ViewRecord
{
    protected static string $resource = AppointmentRescheduleRequestResource::class;

    public function getTitle(): string
    {
        return 'Review '.$this->getRecord()->request_number;
    }

    public function getBreadcrumbs(): array
    {
        return [
            AppointmentRescheduleRequestResource::getUrl('index') => 'Reschedule Requests',
            $this->getRecord()->request_number,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToQueue')
                ->label('Back to queue')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(AppointmentRescheduleRequestResource::getUrl('index')),

            Action::make('approve')
                ->label('Approve request')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->isPending() && $this->availableChoiceOptions() !== [])
                ->authorize('update')
                ->schema([
                    Select::make('selected_scheduled_at')
                        ->label('Approved appointment time')
                        ->options(fn (): array => $this->availableChoiceOptions())
                        ->required()
                        ->native(false)
                        ->helperText('Only currently available times submitted by the patient are listed.'),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $reviewer = auth()->user();

                    if (! $reviewer instanceof User) {
                        $this->notifyFailure('Cannot approve request', 'Your session is no longer authorized.');

                        return;
                    }

                    try {
                        app(ApproveAppointmentRescheduleRequest::class)->handle(
                            request: $this->getRecord(),
                            selectedScheduledAt: Carbon::parse($data['selected_scheduled_at'], config('app.timezone')),
                            reviewer: $reviewer,
                        );

                        $this->getRecord()->refresh();
                        Notification::make()
                            ->title('Reschedule request approved')
                            ->success()
                            ->send();
                    } catch (AppointmentRescheduleRequestStateException|ValidationException|HttpResponseException $exception) {
                        $this->notifyFailure('Cannot approve request', $this->safeExceptionMessage($exception));
                    }
                }),

            Action::make('reject')
                ->label('Reject request')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->getRecord()->isPending())
                ->authorize('update')
                ->schema([
                    Textarea::make('rejection_reason')
                        ->label('Patient-safe reason')
                        ->required()
                        ->maxLength(1000)
                        ->helperText('This reason is sent to the patient. Do not include internal notes or sensitive details.'),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $reviewer = auth()->user();

                    if (! $reviewer instanceof User) {
                        $this->notifyFailure('Cannot reject request', 'Your session is no longer authorized.');

                        return;
                    }

                    try {
                        app(RejectAppointmentRescheduleRequest::class)->handle(
                            request: $this->getRecord(),
                            rejectionReason: $data['rejection_reason'] ?? null,
                            reviewer: $reviewer,
                        );

                        $this->getRecord()->refresh();
                        Notification::make()
                            ->title('Reschedule request rejected')
                            ->success()
                            ->send();
                    } catch (AppointmentRescheduleRequestStateException|ValidationException $exception) {
                        $this->notifyFailure('Cannot reject request', $this->safeExceptionMessage($exception));
                    }
                }),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function availableChoiceOptions(): array
    {
        /** @var AppointmentRescheduleRequest $record */
        $record = $this->getRecord()->loadMissing([
            'appointment.optometrist',
            'appointment.status',
        ]);

        if (! $record->isPending()) {
            return [];
        }

        $appointment = $record->appointment;
        $evaluator = app(EvaluateAppointmentAvailability::class);

        return collect($record->submittedScheduledTimes())
            ->filter(function (CarbonInterface $scheduledAt) use ($appointment, $evaluator): bool {
                return $evaluator->handle(
                    startsAt: $scheduledAt,
                    durationMinutes: (int) ($appointment?->duration_minutes ?? 30),
                    optometrist: $appointment?->optometrist,
                    ignoreAppointment: $appointment,
                    enforceFuture: true,
                    enforceGrid: true,
                )->available;
            })
            ->mapWithKeys(fn (CarbonInterface $scheduledAt, int $index): array => [
                $scheduledAt->toIso8601String() => ($index === 0 ? 'Preferred — ' : 'Alternative '.($index).' — ')
                    .$scheduledAt->format('M j, Y g:i A'),
            ])
            ->all();
    }

    private function notifyFailure(string $title, string $message): void
    {
        Notification::make()
            ->title($title)
            ->body($message)
            ->danger()
            ->send();
    }

    private function safeExceptionMessage(
        AppointmentRescheduleRequestStateException|ValidationException|HttpResponseException $exception,
    ): string {
        if ($exception instanceof ValidationException) {
            return (string) (collect($exception->errors())->flatten()->first() ?? 'The request could not be resolved.');
        }

        if ($exception instanceof HttpResponseException) {
            $message = $exception->getResponse()->json('message');

            return is_string($message) ? $message : 'The selected time is no longer available.';
        }

        return $exception->getMessage();
    }
}
