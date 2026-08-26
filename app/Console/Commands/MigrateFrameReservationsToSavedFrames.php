<?php

namespace App\Console\Commands;

use App\Actions\SavedFrames\ConvertFrameReservation;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\InventoryMovement;
use App\Models\SavedFrame;
use Illuminate\Console\Command;

class MigrateFrameReservationsToSavedFrames extends Command
{
    protected $signature = 'saved-frames:migrate-reservations
        {--dry-run : Report effects without making changes}
        {--execute : Process and convert all reservations}';

    protected $description = 'Convert existing frame reservations to saved frames and release held stock';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $execute = $this->option('execute');

        if (! $dryRun && ! $execute) {
            $this->error('Please specify either --dry-run or --execute.');

            return self::FAILURE;
        }

        $reservations = FrameReservation::query()
            ->with(['items', 'patient'])
            ->get();

        $totalReservations = $reservations->count();
        $totalItems = FrameReservationItem::query()->count();
        $heldItems = $reservations->filter(fn ($r) => $r->isHeld())
            ->sum(fn ($r) => $r->items()->count());
        $linkedReservations = $reservations->filter(fn ($r) => $r->patient?->user_id !== null)->count();
        $unlinkedReservations = $totalReservations - $linkedReservations;

        $this->info("Reservations: {$totalReservations}");
        $this->info("Total items: {$totalItems}");
        $this->info("Held items (will release): {$heldItems}");
        $this->info("Linked reservations (will create saved frames): {$linkedReservations}");
        $this->info("Unlinked reservations (stock release only): {$unlinkedReservations}");

        if ($dryRun) {
            $this->info('Dry run complete. No changes made.');

            return self::SUCCESS;
        }

        $converted = 0;
        $failed = 0;

        foreach ($reservations as $reservation) {
            try {
                app(ConvertFrameReservation::class)->handle($reservation);
                $converted++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Failed to convert reservation #{$reservation->id}: {$e->getMessage()}");
            }
        }

        $remainingReservations = FrameReservation::query()->count();
        $remainingItems = FrameReservationItem::query()->count();
        $savedFrames = SavedFrame::query()->count();
        $releaseMovements = InventoryMovement::query()
            ->whereHas('movementType', fn ($q) => $q->where('name', 'reservation_release'))
            ->count();

        $this->info("Converted: {$converted}");
        $this->info("Failed: {$failed}");
        $this->info("Remaining reservations: {$remainingReservations}");
        $this->info("Remaining items: {$remainingItems}");
        $this->info("Saved frames created: {$savedFrames}");
        $this->info("Release movements: {$releaseMovements}");

        if ($remainingReservations > 0 || $remainingItems > 0) {
            $this->error('Verification failed: reservation rows remain.');

            return self::FAILURE;
        }

        $this->info('Migration complete. All reservation rows removed.');

        return self::SUCCESS;
    }
}
