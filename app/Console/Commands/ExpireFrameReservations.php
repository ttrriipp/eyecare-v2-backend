<?php

namespace App\Console\Commands;

use App\Actions\Appointments\ClinicSchedule;
use App\Actions\Reservations\DeleteFrameReservation;
use App\Models\FrameReservation;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ExpireFrameReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Delete reservations past their derived expiry or whose appointment is no longer scheduled (idempotent)';

    public function handle(): int
    {
        $deleteAction = app(DeleteFrameReservation::class);
        $deleted = 0;

        $reservations = FrameReservation::query()
            ->with('appointment')
            ->get();

        foreach ($reservations as $reservation) {
            $appointment = $reservation->appointment;

            if ($appointment === null) {
                continue;
            }

            $shouldDelete = false;

            if ($appointment->status->name !== 'scheduled') {
                $shouldDelete = true;
            } else {
                $appointmentDate = $appointment->scheduled_at;

                if ($appointmentDate !== null) {
                    $schedule = ClinicSchedule::forDate($appointmentDate);
                    $expiresAt = Carbon::parse($appointmentDate->toDateString().' '.$schedule->closeTime);

                    if (now()->gte($expiresAt)) {
                        $shouldDelete = true;
                    }
                }
            }

            if ($shouldDelete) {
                try {
                    $deleteAction->handle($reservation);
                    $deleted++;
                } catch (\Throwable $e) {
                    $this->warn("Failed to delete reservation #{$reservation->id}: {$e->getMessage()}");
                }
            }
        }

        $this->info("Deleted {$deleted} expired reservation(s).");

        return self::SUCCESS;
    }
}
