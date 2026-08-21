<?php

namespace App\Filament\Resources\AppointmentRequests\Pages;

use App\Actions\Appointments\AcceptAppointmentRequest;
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
        $this->scheduledDate = $this->record->scheduled_at?->toDateString() ?? today()->toDateString();
        $this->scheduledTime = $this->record->scheduled_at?->format('H:i') ?? '09:00';
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
        return match ($reason) {
            null => 'Available',
            'clinic_closed' => 'Clinic closed',
            'outside_clinic_hours' => 'Outside clinic hours',
            'capacity_reached' => 'Provider unavailable / capacity reached',
            'elapsed' => 'Elapsed',
            'outside_slot_grid' => 'Outside available time grid',
            default => Str::headline($reason),
        };
    }

    public function selectPreference(int $index): void
    {
        $preference = $this->getRecord()->getAllTimePreferences()[$index] ?? null;

        if ($preference === null) {
            return;
        }

        $selected = Carbon::parse($preference);
        $this->scheduledDate = $selected->toDateString();
        $this->scheduledTime = $selected->format('H:i');
        $this->resetValidation();
        $this->focusCalendar();
    }

    public function selectCalendarSlot(string $start): void
    {
        $selected = Carbon::parse($start);
        $this->scheduledDate = $selected->toDateString();
        $this->scheduledTime = $selected->format('H:i');
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
            'optometristId' => ['required', 'integer'],
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

        if ($optometrist === null) {
            $this->addError('optometristId', 'Select an active optometrist.');

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

    public function selectedDateTime(): string
    {
        try {
            return $this->selectedScheduledAt()->toIso8601String();
        } catch (\Throwable) {
            return now()->toIso8601String();
        }
    }

    public function isSelectedPreference(string $start): bool
    {
        try {
            return Carbon::parse($start)->equalTo($this->selectedScheduledAt());
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
            ->contains(fn (string $preference): bool => Carbon::parse($preference)->equalTo($selected));
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
        return Carbon::parse($this->scheduledDate.' '.$this->scheduledTime);
    }

    private function focusCalendar(): void
    {
        $this->dispatch(
            'appointment-request-calendar-focus',
            start: $this->selectedDateTime(),
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
