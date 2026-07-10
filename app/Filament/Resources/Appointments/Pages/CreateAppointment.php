<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Actions\Appointments\ScheduleAppointment;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\AppointmentStatus;
use App\Models\User;
use App\Models\VisitReason;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;
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
            $this->data['scheduled_at'] = Carbon::parse($scheduledAt)->toDateTimeString();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['appointment_status_id'] = AppointmentStatus::query()
            ->where('name', 'confirmed')
            ->value('id');

        return $data;
    }

    protected function beforeCreate(): void
    {
        app(ScheduleAppointment::class)->handle(
            scheduledAt: Carbon::parse($this->data['scheduled_at']),
            visitReason: VisitReason::query()->findOrFail($this->data['visit_reason_id']),
            optometrist: filled($this->data['optometrist_id'] ?? null)
                ? User::query()->findOrFail($this->data['optometrist_id'])
                : null,
        );
    }
}
