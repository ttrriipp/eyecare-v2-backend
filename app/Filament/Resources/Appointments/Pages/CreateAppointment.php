<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Actions\Appointments\LockAppointmentScheduleDate;
use App\Actions\Appointments\ScheduleAppointment;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Support\AppointmentTime;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class CreateAppointment extends CreateRecord
{
    protected static string $resource = AppointmentResource::class;

    /**
     * Pre-fills the scheduled time when the page is opened from the calendar
     * (e.g. /admin/appointments/create?scheduled_at=2026-07-01 10:00:00).
     */
    #[Url(as: 'scheduled_at')]
    public ?string $scheduledAt = null;

    public function mount(): void
    {
        parent::mount();

        $scheduledAt = $this->scheduledAt ?? request()->query('scheduled_at');

        if ($scheduledAt) {
            $dateTime = Carbon::parse($scheduledAt);
            $this->data['scheduled_at'] = $dateTime->toDateString();
            $this->data['appointment_time'] = $dateTime->format('H:i');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Create patient from new patient fields if mode is 'new'
        if (empty($data['patient_id']) && filled($data['new_patient_full_name'] ?? null)) {
            $patient = Patient::create([
                'full_name' => $data['new_patient_full_name'],
                'phone' => $data['new_patient_phone'] ?? null,
                'contact_email' => $data['new_patient_contact_email'] ?? null,
                'date_of_birth' => $data['new_patient_date_of_birth'] ?? null,
                'gender' => $data['new_patient_gender'] ?? null,
                'occupation' => $data['new_patient_occupation'] ?? null,
                'address' => $data['new_patient_address'] ?? null,
            ]);
            $data['patient_id'] = $patient->getKey();
        }

        // Strip virtual patient fields
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, 'new_patient_') || $key === 'patient_mode') {
                unset($data[$key]);
            }
        }

        $data['scheduled_at'] = AppointmentTime::combine(
            $data['scheduled_at'],
            $data['appointment_time'],
        );
        unset($data['appointment_time']);

        $data['appointment_status_id'] = AppointmentStatus::query()
            ->where('name', 'confirmed')
            ->value('id');

        $appointmentType = AppointmentType::query()->findOrFail($data['appointment_type_id']);
        $data['duration_minutes'] = $appointmentType->duration_minutes;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Appointment {
            app(LockAppointmentScheduleDate::class)->handle($data['scheduled_at']);

            app(ScheduleAppointment::class)->handle(
                scheduledAt: $data['scheduled_at'],
                durationMinutes: $data['duration_minutes'],
                optometrist: filled($data['optometrist_id'] ?? null)
                    ? User::query()->findOrFail($data['optometrist_id'])
                    : null,
            );

            return Appointment::query()->create($data);
        }, attempts: 3);
    }
}
