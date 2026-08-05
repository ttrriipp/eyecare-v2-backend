<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\ScheduleOverride;
use Illuminate\Support\Facades\DB;

class DeleteScheduleOverride
{
    public function handle(ScheduleOverride $override): void
    {
        DB::transaction(function () use ($override): void {
            $metadata = [
                'type' => $override->type->value,
                'override_date' => $override->override_date->toDateString(),
                'user_id' => $override->user_id,
                'start_time' => $override->start_time?->format('H:i'),
                'end_time' => $override->end_time?->format('H:i'),
                'reason' => $override->reason,
            ];

            $override->delete();

            app(CreateAuditLog::class)->handle(
                subject: $override,
                action: AuditEvent::ScheduleOverrideRemoved,
                metadata: $metadata,
            );
        });
    }
}
