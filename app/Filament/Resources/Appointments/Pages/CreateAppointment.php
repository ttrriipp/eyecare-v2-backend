<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Actions\Appointments\LockAppointmentScheduleDate;
use App\Actions\Appointments\ScheduleAppointment;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Support\AppointmentTime;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use App\Models\VisitReason;
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
        $data['scheduled_at'] = AppointmentTime::combine(
            $data['scheduled_at'],
            $data['appointment_time'],
        );
        unset($data['appointment_time']);

        $data['appointment_status_id'] = AppointmentStatus::query()
            ->where('name', 'confirmed')
            ->value('id');

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
                visitReason: VisitReason::query()->findOrFail($data['visit_reason_id']),
                optometrist: filled($data['optometrist_id'] ?? null)
                    ? User::query()->findOrFail($data['optometrist_id'])
                    : null,
            );

            return Appointment::query()->create($data);
        }, attempts: 3);
    }
}
