<?php

namespace App\Console\Commands;

use App\Actions\Reservations\ReleaseFrameReservation;
use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use Illuminate\Console\Command;

class ExpirePreparedReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Release expired prepared and unresolved tried-on reservations (idempotent)';

    public function handle(): int
    {
        $releaseAction = app(ReleaseFrameReservation::class);
        $released = 0;

        // Release expired prepared reservations
        $expiredPrepared = FrameReservation::query()
            ->where('status', ReservationStatus::Prepared)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expiredPrepared as $reservation) {
            try {
                $releaseAction->handle($reservation);
                $released++;
            } catch (\Throwable $e) {
                $this->warn("Failed to release prepared reservation #{$reservation->id}: {$e->getMessage()}");
            }
        }

        // Auto-release unresolved tried-on reservations whose appointment has passed
        $staleTriedOn = FrameReservation::query()
            ->where('status', ReservationStatus::TriedOn)
            ->whereHas('appointment', fn ($query) => $query->where('scheduled_at', '<', now()))
            ->get();

        foreach ($staleTriedOn as $reservation) {
            try {
                $releaseAction->handle($reservation);
                $released++;
            } catch (\Throwable $e) {
                $this->warn("Failed to release tried-on reservation #{$reservation->id}: {$e->getMessage()}");
            }
        }

        $this->info("Released {$released} reservation(s).");

        return self::SUCCESS;
    }
}
