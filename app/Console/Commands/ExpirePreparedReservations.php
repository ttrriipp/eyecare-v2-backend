<?php

namespace App\Console\Commands;

use App\Actions\Reservations\ReleaseFrameReservation;
use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use Illuminate\Console\Command;

class ExpirePreparedReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Release expired prepared reservations (idempotent)';

    public function handle(): int
    {
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
}
