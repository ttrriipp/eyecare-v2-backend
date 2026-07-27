<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\Prescription;
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
            ->with(['patient', 'appointmentType', 'status', 'optometrist', 'encounter'])
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

        // Load intake for this specific appointment
        $intake = $appointment->intake;

        // Load encounter and prescription
        $encounter = $appointment->encounter;
        $prescription = $encounter
            ? Prescription::query()->where('encounter_id', $encounter->id)->first()
            : null;

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
            'encounterData' => $encounter ? [
                'encounter_number' => $encounter->encounter_number,
                'status' => $encounter->status?->value ?? '—',
                'optometrist' => $encounter->optometrist?->name ?? '—',
                'findings' => $encounter->findings ?? '—',
                'remarks' => $encounter->remarks ?? '—',
            ] : null,
            'prescriptionData' => $prescription ? [
                'od_sphere' => $prescription->od_sphere ?? '—',
                'od_cylinder' => $prescription->od_cylinder ?? '—',
                'od_axis' => $prescription->od_axis ?? '—',
                'od_add' => $prescription->od_add ?? '—',
                'os_sphere' => $prescription->os_sphere ?? '—',
                'os_cylinder' => $prescription->os_cylinder ?? '—',
                'os_axis' => $prescription->os_axis ?? '—',
                'os_add' => $prescription->os_add ?? '—',
                'pd' => $prescription->pd ?? '—',
                'prescribed_at' => $prescription->prescribed_at?->format('M d, Y') ?? '—',
                'expires_at' => $prescription->expires_at?->format('M d, Y') ?? '—',
                'notes' => $prescription->notes ?? '—',
            ] : null,
        ];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->check();
    }
}
