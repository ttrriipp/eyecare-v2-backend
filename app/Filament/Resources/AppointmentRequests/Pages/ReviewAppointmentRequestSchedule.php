<?php

namespace App\Filament\Resources\AppointmentRequests\Pages;

use App\Actions\Appointments\AcceptAppointmentRequest;
use App\Actions\Appointments\EvaluateAppointmentAvailability;
use App\Actions\Appointments\EvaluateAppointmentRequestPreferences;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Filament\Resources\AppointmentRequests\Widgets\AppointmentRequestScheduleCalendar;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\AppointmentType;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class ReviewAppointmentRequestSchedule extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AppointmentRequestResource::class;

    protected string $view = 'filament.resources.appointment-requests.pages.review-appointment-request-schedule';

    public ?int $appointmentTypeId = null;

    public int $durationMinutes = 30;

    public ?int $optometristId = null;

    public string $scheduledDate = '';

    public string $scheduledTime = '';

    public ?string $referringSource = null;

    public ?string $contactNote = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth()->user()?->can('accept', $this->record), 403);

        $defaultType = $this->record->appointmentType;

        if ($defaultType === null || ! $defaultType->is_active) {
            $defaultType = AppointmentType::active()->where('name', 'New Patient')->first()
                ?? AppointmentType::active()->first();
        }

        $this->appointmentTypeId = $defaultType?->id;
        $this->durationMinutes = $defaultType?->duration_minutes ?? $this->record->provisional_duration_minutes ?? 30;
        $this->setScheduledSlot($this->record->scheduled_at ?? today()->setTime(9, 0));
        $this->referringSource = $this->record->encrypted_referring_source;
    }

    public function getTitle(): string
    {
        return 'Review & Schedule';
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToRequest')
                ->label('Back to Request')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->outlined()
                ->url(AppointmentRequestResource::getUrl('view', ['record' => $this->getRecord()])),
            Action::make('accept')
                ->label('Accept & Schedule')
                ->icon('heroicon-o-check')
                ->color('success')
                ->action(function (): void {
                    $this->accept();
                }),
        ];
    }

    public function appointmentTypes(): array
    {
        return AppointmentType::active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function optometrists(): array
    {
        return User::query()
            ->optometrists()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name])
            ->all();
    }

    /**
     * @return array<int, array{
     *     preference: string,
     *     starts_at: CarbonInterface,
     *     ends_at: CarbonInterface,
     *     available: bool,
     *     reason: ?string,
     * }>
     */
    public function preferenceDecisions(): array
    {
        return app(EvaluateAppointmentRequestPreferences::class)->handle(
            request: $this->getRecord(),
            durationMinutes: $this->durationMinutes,
            optometrist: $this->selectedOptometrist(),
        );
    }

    public function reasonLabel(?string $reason): string
    {
        if ($reason === null) {
            return $this->optometristId === null ? 'Clinic capacity available' : 'Provider available';
        }

        return match ($reason) {
            'clinic_closed' => 'Clinic closed',
            'outside_clinic_hours' => 'Outside clinic hours',
            'capacity_reached' => $this->optometristId === null
                ? 'Clinic capacity reached'
                : 'Provider unavailable / capacity reached',
            'elapsed' => 'Elapsed',
            'outside_slot_grid' => 'Outside available time grid',
            default => Str::headline($reason),
        };
    }

    public function preferenceAvailabilityLabel(bool $available, ?string $reason): string
    {
        if ($available) {
            return 'Available';
        }

        return match ($reason) {
            'clinic_closed' => 'Clinic closed',
            'outside_clinic_hours' => 'Outside clinic hours',
            'capacity_reached' => $this->optometristId === null ? 'Capacity reached' : 'Provider unavailable',
            'elapsed' => 'Elapsed',
            'outside_slot_grid' => 'Outside available grid',
            default => 'Unavailable',
        };
    }

    /**
     * @return array{state: 'available'|'unavailable'|'incomplete', label: string}
     */
    public function selectedSlotStatus(): array
    {
        $appointmentType = $this->selectedAppointmentType();

        if ($appointmentType === null) {
            return [
                'state' => 'incomplete',
                'label' => 'Select an appointment type',
            ];
        }

        if ($this->durationMinutes < 5 || $this->durationMinutes > 240) {
            return [
                'state' => 'incomplete',
                'label' => 'Enter a valid duration',
            ];
        }

        try {
            $startsAt = $this->selectedScheduledAt();
        } catch (\Throwable) {
            return [
                'state' => 'incomplete',
                'label' => 'Enter a valid date and time',
            ];
        }

        $optometrist = $this->selectedOptometrist();

        if ($this->optometristId !== null && $optometrist === null) {
            return [
                'state' => 'incomplete',
                'label' => 'Select an active optometrist or leave the provider unassigned',
            ];
        }

        $evaluator = app(EvaluateAppointmentAvailability::class);
        $decision = $evaluator->handle(
            startsAt: $startsAt,
            durationMinutes: $this->durationMinutes,
            optometrist: $optometrist,
            enforceFuture: true,
            enforceGrid: true,
        );

        if (! $decision->available) {
            return [
                'state' => 'unavailable',
                'label' => $this->unavailableSlotLabel($decision->reason, $optometrist),
            ];
        }

        if ($optometrist !== null) {
            return [
                'state' => 'available',
                'label' => 'Provider available',
            ];
        }

        $capacity = $evaluator->clinicCapacityForInterval(
            startsAt: $decision->startsAt,
            endsAt: $decision->endsAt,
        );

        if ($capacity['total'] === 0) {
            return [
                'state' => 'available',
                'label' => 'Clinic capacity available',
            ];
        }

        return [
            'state' => 'available',
            'label' => sprintf(
                '%d of %d clinic slots available',
                $capacity['available'],
                $capacity['total'],
            ),
        ];
    }

    public function selectPreference(int $index): void
    {
        $preference = $this->getRecord()->getAllTimePreferences()[$index] ?? null;

        if ($preference === null) {
            return;
        }

        $selected = Carbon::parse($preference);
        $this->setScheduledSlot($selected);
        $this->resetValidation();
        $this->focusCalendar();
    }

    public function selectCalendarSlot(string $start): void
    {
        $selected = Carbon::parse($start);
        $this->setScheduledSlot($selected);
        $this->resetValidation();
    }

    #[On('appointment-request-schedule-slot-selected')]
    public function receiveCalendarSlot(string $start): void
    {
        $this->selectCalendarSlot($start);
    }

    public function updatedAppointmentTypeId(?int $typeId): void
    {
        $type = $typeId === null ? null : AppointmentType::find($typeId);

        if ($type !== null) {
            $this->durationMinutes = $type->duration_minutes;
        }

        $this->resetValidation();
        $this->focusCalendar();
    }

    public function updatedDurationMinutes(): void
    {
        $this->resetValidation();
        $this->focusCalendar();
    }

    public function updatedOptometristId(): void
    {
        $this->resetValidation();
        $this->focusCalendar();
    }

    public function accept(): void
    {
        try {
            Gate::authorize('accept', $this->getRecord());
        } catch (AuthorizationException) {
            $message = 'This request is no longer available for scheduling. Refresh the page to see its latest status.';
            $this->addError('scheduledDate', $message);
            Notification::make()
                ->title('Request is no longer pending')
                ->body($message)
                ->warning()
                ->send();

            return;
        }

        $this->validate([
            'appointmentTypeId' => ['required', 'integer'],
            'durationMinutes' => ['required', 'integer', 'min:5', 'max:240'],
            'optometristId' => ['nullable', 'integer'],
            'scheduledDate' => ['required', 'date'],
            'scheduledTime' => ['required', 'date_format:H:i'],
            'referringSource' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->selectedAppointmentType()?->requires_referral && blank($value)) {
                        $fail('A referring source is required for this appointment type.');
                    }
                },
            ],
            'contactNote' => [
                'nullable',
                'string',
                'max:1000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $this->matchesSubmittedPreference() && blank($value)) {
                        $fail('A contact note is required when the final time differs from submitted preferences.');
                    }
                },
            ],
        ]);

        $scheduledAt = $this->selectedScheduledAt();
        $appointmentType = $this->selectedAppointmentType();
        $optometrist = $this->selectedOptometrist();

        if ($appointmentType === null) {
            $this->addError('appointmentTypeId', 'Select an active appointment type.');

            return;
        }

        if ($this->optometristId !== null && $optometrist === null) {
            $this->addError('optometristId', 'Select an active optometrist or leave the provider unassigned.');

            return;
        }

        try {
            $appointment = app(AcceptAppointmentRequest::class)->handle(
                request: $this->getRecord(),
                reviewer: auth()->user(),
                appointmentType: $appointmentType,
                durationMinutes: $this->durationMinutes,
                scheduledAt: $scheduledAt,
                optometrist: $optometrist,
                referringSource: $this->referringSource,
                contactNote: $this->contactNote,
            );
        } catch (ValidationException $exception) {
            $this->getRecord()->refresh();
            $this->addValidationErrors($exception);
            $this->focusCalendar();
            Notification::make()
                ->title('Cannot schedule request')
                ->body('Review the highlighted scheduling fields and try again.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Appointment scheduled')
            ->body("Appointment {$appointment->appointment_number} was created.")
            ->success()
            ->send();

        $this->redirect(AppointmentResource::getUrl('edit', ['record' => $appointment]));
    }

    public function selectedDateTime(): ?string
    {
        try {
            return $this->selectedScheduledAt()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    public function isSelectedPreference(string $start): bool
    {
        try {
            return $this->matchesScheduledMinute(Carbon::parse($start), $this->selectedScheduledAt());
        } catch (\Throwable) {
            return false;
        }
    }

    public function matchesSubmittedPreference(): bool
    {
        try {
            $selected = $this->selectedScheduledAt();
        } catch (\Throwable) {
            return false;
        }

        return collect($this->getRecord()->getAllTimePreferences())
            ->contains(fn (string $preference): bool => $this->matchesScheduledMinute(Carbon::parse($preference), $selected));
    }

    private function selectedAppointmentType(): ?AppointmentType
    {
        return $this->appointmentTypeId === null
            ? null
            : AppointmentType::active()->find($this->appointmentTypeId);
    }

    private function selectedOptometrist(): ?User
    {
        return $this->optometristId === null
            ? null
            : User::query()->optometrists()->find($this->optometristId);
    }

    private function selectedScheduledAt(): Carbon
    {
        if (blank($this->scheduledDate) || blank($this->scheduledTime)) {
            throw new \InvalidArgumentException('A date and time are required to identify the selected slot.');
        }

        return Carbon::parse($this->scheduledDate.' '.$this->scheduledTime, config('app.timezone'));
    }

    private function unavailableSlotLabel(?string $reason, ?User $optometrist): string
    {
        return match ($reason) {
            'capacity_reached' => $optometrist === null
                ? 'Unavailable — capacity reached'
                : 'Unavailable — provider unavailable',
            'clinic_closed' => 'Unavailable — clinic closed',
            'outside_clinic_hours' => 'Unavailable — outside clinic hours',
            'elapsed' => 'Unavailable — elapsed',
            'outside_slot_grid' => 'Unavailable — outside available time grid',
            default => 'Unavailable — '.Str::lower(Str::headline($reason ?? 'unavailable')),
        };
    }

    private function setScheduledSlot(CarbonInterface $selected): void
    {
        $selected = $selected->copy()->setTimezone(config('app.timezone'));
        $this->scheduledDate = $selected->toDateString();
        $this->scheduledTime = $selected->format('H:i');
    }

    private function matchesScheduledMinute(CarbonInterface $first, CarbonInterface $second): bool
    {
        return $first->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i')
            === $second->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i');
    }

    private function focusCalendar(): void
    {
        $selectedDateTime = $this->selectedDateTime();

        if ($selectedDateTime === null) {
            return;
        }

        $this->dispatch(
            'appointment-request-calendar-focus',
            start: $selectedDateTime,
        )->to(AppointmentRequestScheduleCalendar::class);
    }

    private function addValidationErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $attribute => $messages) {
            $field = match ($attribute) {
                'scheduled_at' => 'scheduledDate',
                'optometrist_id' => 'optometristId',
                'appointment_type_id' => 'appointmentTypeId',
                'duration_minutes' => 'durationMinutes',
                'referring_source' => 'referringSource',
                'contact_note' => 'contactNote',
                default => 'scheduledDate',
            };

            foreach ($messages as $message) {
                $this->addError($field, $message);
            }
        }
    }
}
