<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use Filament\Resources\Pages\Page;
use Illuminate\Http\Request;

class HealthRecord extends Page
{
    protected static string $resource = AppointmentResource::class;

    protected string $view = 'filament.resources.appointments.pages.health-record';

    public ?Appointment $appointment = null;

    public function mount(Request $request, int|string $record): void
    {
        $this->appointment = Appointment::query()
            ->with(['patient', 'appointmentType', 'status', 'optometrist', 'encounters'])
            ->findOrFail($record);
    }

    public function getTitle(): string
    {
        return 'Patient Health Record';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $appointment = $this->appointment;
        $patient = $appointment->patient;

        // Load intake separately
        $intake = $patient?->intakes()
            ->latest('submitted_at')
            ->first();

        return [
            'appointmentData' => [
                'appointment_number' => $appointment->appointment_number,
                'appointment_type' => $appointment->appointmentType?->name ?? '—',
                'referring_source' => $appointment->referring_source ?? '—',
                'scheduled_at' => $appointment->scheduled_at?->format('M d, Y g:i A') ?? '—',
                'status' => $appointment->status?->name ?? '—',
                'optometrist' => $appointment->optometrist?->name ?? 'Unassigned',
            ],
            'patientData' => [
                'full_name' => $patient?->full_name ?? '—',
                'date_of_birth' => $patient?->date_of_birth?->format('M d, Y') ?? '—',
                'gender' => $patient?->gender ?? '—',
                'occupation' => $patient?->occupation ?? '—',
                'phone' => $patient?->phone ?? '—',
                'address' => $patient?->address ?? '—',
            ],
            'intakeData' => [
                'chief_complaint' => $intake?->chief_complaint ?? '—',
                'past_ocular_history' => $intake?->past_ocular_history ?? '—',
                'past_surgical_history' => $intake?->past_surgical_history ?? '—',
                'past_medical_history' => $intake?->past_medical_history ?? '—',
                'allergies' => $intake?->allergies ?? '—',
                'medications' => $intake?->medications ?? '—',
            ],
            'encounterData' => $appointment->encounters->map(fn ($encounter) => [
                'encounter_number' => $encounter->encounter_number,
                'findings' => $encounter->findings ?? '—',
                'remarks' => $encounter->remarks ?? '—',
            ])->toArray(),
        ];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->check();
    }
}
