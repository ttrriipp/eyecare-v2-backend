<?php

namespace App\Console\Commands;

use App\Actions\Reservations\ReleaseFrameReservation;
use App\Enums\ReservationStatus;
use App\Models\ClinicHour;
use App\Models\FrameReservation;
use App\Models\ScheduleOverride;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ExpirePreparedReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Release expired prepared reservations (idempotent, respects clinic closing)';

    public function handle(): int
    {
        $today = Carbon::today();

        $clinicCloseTime = $this->resolveClinicCloseTime($today);

        $expired = FrameReservation::query()
            ->where('status', ReservationStatus::Prepared)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $released = 0;

        foreach ($expired as $reservation) {
            try {
                app(ReleaseFrameReservation::class)->handle($reservation);
                $released++;
            } catch (\Throwable $e) {
                $this->warn("Failed to release reservation #{$reservation->id}: {$e->getMessage()}");
            }
        }

        $this->info("Released {$released} expired reservation(s).");

        return self::SUCCESS;
    }

    private function resolveClinicCloseTime(Carbon $date): string
    {
        $weekday = $date->dayOfWeek;

        $clinicHour = ClinicHour::query()
            ->where('weekday', $weekday)
            ->first();

        if ($clinicHour === null || ! $clinicHour->enabled) {
            return '17:00'; // Default
        }

        // Check for early close override
        $earlyClose = ScheduleOverride::query()
            ->where('override_date', $date->toDateString())
            ->where('type', ScheduleOverride::TYPE_EARLY_CLOSE)
            ->whereNotNull('start_time')
            ->first();

        if ($earlyClose !== null) {
            return $earlyClose->start_time->format('H:i');
        }

        return $clinicHour->close_time->format('H:i');
    }
}
