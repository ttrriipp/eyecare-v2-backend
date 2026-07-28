<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Actions\Intakes\ReturnIntakeForCorrection;
use App\Actions\Intakes\VerifyPatientIntake;
use App\Enums\IntakeStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\PatientIntake;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class IntakeForm extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AppointmentResource::class;

    protected string $view = 'filament.resources.appointments.pages.intake-form';

    public ?Appointment $appointment = null;

    public ?array $formData = [];

    public function mount(Request $request, int|string $record): void
    {
        $this->appointment = Appointment::query()
            ->with(['patient', 'appointmentType', 'status', 'intake.submittedBy', 'intake.verifiedBy'])
            ->findOrFail($record);

        $this->formData = $this->getInitialData();
    }

    public function getRecord()
    {
        return $this->appointment;
    }

    public function getTitle(): string
    {
        return 'Patient Health Record';
    }

    public function getIntake(): ?PatientIntake
    {
        return $this->appointment?->intake;
    }

    public function getIntakeStatus(): ?IntakeStatus
    {
        return $this->getIntake()?->status;
    }

    public function isReadOnly(): bool
    {
        $status = $this->getIntakeStatus();

        return $status === IntakeStatus::Submitted || $status === IntakeStatus::Verified;
    }

    public function getStatusBadgeColor(): string
    {
        return match ($this->getIntakeStatus()) {
            null => 'gray',
            IntakeStatus::Draft => 'warning',
            IntakeStatus::Submitted => 'info',
            IntakeStatus::Verified => 'success',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->getIntakeStatus()) {
            null => 'Not started',
            IntakeStatus::Draft => 'Incomplete',
            IntakeStatus::Submitted => 'Needs review',
            IntakeStatus::Verified => 'Verified',
        };
    }

    protected function getInitialData(): array
    {
        $intake = $this->getIntake();
        $patient = $this->appointment?->patient;

        if ($intake) {
            return [
                'full_name' => $intake->full_name,
                'date_of_birth' => $intake->date_of_birth?->format('Y-m-d'),
                'gender' => $intake->gender,
                'occupation' => $intake->occupation,
                'address' => $intake->address,
                'phone' => $intake->phone,
                'email' => $intake->email,
                'chief_complaint' => $intake->chief_complaint,
                'past_ocular_history' => $intake->past_ocular_history,
                'past_surgical_history' => $intake->past_surgical_history,
                'past_medical_history' => $intake->past_medical_history,
                'allergies' => $intake->allergies,
                'medications' => $intake->medications,
            ];
        }

        return [
            'full_name' => $patient?->full_name,
            'date_of_birth' => $patient?->date_of_birth?->format('Y-m-d'),
            'gender' => $patient?->gender,
            'occupation' => $patient?->occupation,
            'address' => $patient?->address,
            'phone' => $patient?->phone,
            'email' => $patient?->contact_email,
            'chief_complaint' => null,
            'past_ocular_history' => null,
            'past_surgical_history' => null,
            'past_medical_history' => null,
            'allergies' => null,
            'medications' => null,
        ];
    }

    public function saveDraft(): void
    {
        $this->save(IntakeStatus::Draft);
    }

    public function submit(): void
    {
        $this->save(IntakeStatus::Submitted);
    }

    public function saveAndVerify(): void
    {
        $this->save(IntakeStatus::Submitted);
        $this->verify();
    }

    public function verify(): void
    {
        $intake = $this->getIntake();

        if ($intake === null || $intake->status !== IntakeStatus::Submitted) {
            Notification::make()
                ->title('Only submitted records can be verified')
                ->danger()
                ->send();

            return;
        }

        try {
            app(VerifyPatientIntake::class)->handle($intake, auth()->user());

            Notification::make()
                ->title('Health record verified')
                ->success()
                ->send();

            $this->appointment->load('intake');
            $this->formData = $this->getInitialData();
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Cannot verify health record')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function returnForCorrection(): void
    {
        $intake = $this->getIntake();

        if ($intake === null || $intake->status !== IntakeStatus::Submitted) {
            Notification::make()
                ->title('Only submitted records can be returned for correction')
                ->danger()
                ->send();

            return;
        }

        try {
            app(ReturnIntakeForCorrection::class)->handle($intake, auth()->user());

            Notification::make()
                ->title('Health record returned for correction')
                ->success()
                ->send();

            $this->appointment->load('intake');
            $this->formData = $this->getInitialData();
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Cannot return health record')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function printRecord(): void
    {
        $this->dispatch('print-health-record');
    }

    protected function save(IntakeStatus $status): void
    {
        $validated = $this->validate([
            'formData.full_name' => ['required', 'string', 'max:255'],
            'formData.date_of_birth' => ['nullable', 'date', 'before:today'],
            'formData.gender' => ['nullable', 'string', 'max:16'],
            'formData.occupation' => ['nullable', 'string', 'max:255'],
            'formData.address' => ['nullable', 'string', 'max:255'],
            'formData.phone' => ['nullable', 'string', 'max:20'],
            'formData.email' => ['nullable', 'email', 'max:255'],
            'formData.chief_complaint' => ['nullable', 'string'],
            'formData.past_ocular_history' => ['nullable', 'string'],
            'formData.past_surgical_history' => ['nullable', 'string'],
            'formData.past_medical_history' => ['nullable', 'string'],
            'formData.allergies' => ['nullable', 'string'],
            'formData.medications' => ['nullable', 'string'],
        ]);

        $data = $validated['formData'];

        $intake = $this->getIntake();

        if ($intake) {
            $intake->update([
                ...$data,
                'status' => $status,
                'appointment_type' => $this->appointment->appointmentType?->name,
                'submitted_by' => $status === IntakeStatus::Submitted ? auth()->id() : $intake->submitted_by,
                'submitted_at' => $status === IntakeStatus::Submitted ? now() : $intake->submitted_at,
            ]);
        } else {
            PatientIntake::create([
                ...$data,
                'patient_id' => $this->appointment->patient_id,
                'appointment_id' => $this->appointment->id,
                'status' => $status,
                'appointment_type' => $this->appointment->appointmentType?->name,
                'submitted_by' => $status === IntakeStatus::Submitted ? auth()->id() : null,
                'submitted_at' => $status === IntakeStatus::Submitted ? now() : null,
            ]);
        }

        Notification::make()
            ->title($status === IntakeStatus::Submitted ? 'Health record submitted for review' : 'Progress saved')
            ->success()
            ->send();

        $this->appointment->load('intake');
        $this->formData = $this->getInitialData();
    }

    public function getBackUrl(): string
    {
        return AppointmentResource::getUrl('edit', ['record' => $this->appointment]);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->check();
    }
}
