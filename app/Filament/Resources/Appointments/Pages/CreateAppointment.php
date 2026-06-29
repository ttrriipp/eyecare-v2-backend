<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\AppointmentStatus;
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
            ->where('name', 'pending')
            ->value('id');

        return $data;
    }
}
