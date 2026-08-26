<?php

namespace App\Console\Commands;

use App\Actions\SavedFrames\ConvertFrameReservation;
use App\Models\InventoryMovement;
use App\Models\SavedFrame;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

#[Signature('saved-frames:migrate-reservations
    {--dry-run : Report effects without making changes}
    {--execute : Process and convert all reservations}')]
#[Description('Convert existing frame reservations to saved frames and release held stock')]
class MigrateFrameReservationsToSavedFrames extends Command
{
    public function __construct(
        private readonly ConvertFrameReservation $convertReservation,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if ($dryRun === $execute) {
            $this->error(
                $dryRun
                    ? 'Choose only one of --dry-run or --execute.'
                    : 'Please specify either --dry-run or --execute.',
            );

            return self::FAILURE;
        }

        $hasReservationsTable = Schema::hasTable('frame_reservations');
        $hasItemsTable = Schema::hasTable('frame_reservation_items');

        if (! $hasReservationsTable && ! $hasItemsTable) {
            $this->info('Reservation tables are absent. Nothing to migrate.');

            return self::SUCCESS;
        }

        if (! $hasReservationsTable || ! $hasItemsTable) {
            $this->error('Reservation tables are inconsistent; restore both tables before retrying.');

            return self::FAILURE;
        }

        $summary = $this->summarizeLegacyReservations();

        $this->info("Reservations: {$summary['reservations']}");
        $this->info("Total items: {$summary['items']}");
        $this->info("Held items (will release): {$summary['held_items']}");
        $this->info("Linked reservations (will create saved frames): {$summary['linked_reservations']}");
        $this->info("Unlinked reservations (stock release only): {$summary['unlinked_reservations']}");

        if ($dryRun) {
            $this->info('Dry run complete. No changes made.');

            return self::SUCCESS;
        }

        $converted = 0;
        $failed = 0;
        $releasedItems = 0;
        $savedFramesCreated = 0;
        $savedFramesUpdated = 0;
        $skippedUnlinkedItems = 0;
        $releaseMovementsBefore = $this->reservationReleaseMovementCount();

        DB::table('frame_reservations')->chunkById(
            100,
            function (Collection $reservations) use (
                &$converted,
                &$failed,
                &$releasedItems,
                &$savedFramesCreated,
                &$savedFramesUpdated,
                &$skippedUnlinkedItems,
            ): void {
                foreach ($reservations as $reservation) {
                    $reservationId = (int) $reservation->id;

                    try {
                        $result = $this->convertReservation->handle($reservationId);
                        $converted++;
                        $releasedItems += $result['released_items'];
                        $savedFramesCreated += $result['saved_frames_created'];
                        $savedFramesUpdated += $result['saved_frames_updated'];
                        $skippedUnlinkedItems += $result['skipped_unlinked_items'];
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->error("Failed to convert reservation #{$reservationId}: {$exception->getMessage()}");
                    }
                }
            },
            'id',
        );

        $remainingReservations = DB::table('frame_reservations')->count();
        $remainingItems = DB::table('frame_reservation_items')->count();
        $releaseMovements = $this->reservationReleaseMovementCount();
        $releaseMovementsCreated = $releaseMovements - $releaseMovementsBefore;

        $this->info("Converted: {$converted}");
        $this->info("Failed: {$failed}");
        $this->info("Released held items: {$releasedItems}");
        $this->info("Saved frames created: {$savedFramesCreated}");
        $this->info("Saved frame timestamps corrected: {$savedFramesUpdated}");
        $this->info("Unlinked choices skipped: {$skippedUnlinkedItems}");
        $this->info("Remaining reservations: {$remainingReservations}");
        $this->info("Remaining items: {$remainingItems}");
        $this->info('Saved frames in database: '.SavedFrame::query()->count());
        $this->info("Reservation release movements created: {$releaseMovementsCreated}");
        $this->info("Reservation release movements: {$releaseMovements}");

        if (
            $failed > 0
            || $remainingReservations > 0
            || $remainingItems > 0
            || $releasedItems !== $summary['held_items']
            || $releaseMovementsCreated !== $releasedItems
        ) {
            $this->error('Verification failed: reservation conversion is incomplete.');

            return self::FAILURE;
        }

        $this->info('Migration complete. All reservation rows removed.');

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     reservations: int,
     *     items: int,
     *     held_items: int,
     *     linked_reservations: int,
     *     unlinked_reservations: int,
     * }
     */
    private function summarizeLegacyReservations(): array
    {
        $reservationCount = (int) DB::table('frame_reservations')->count();
        $itemCount = (int) DB::table('frame_reservation_items')->count();
        $heldItemCount = (int) DB::table('frame_reservations as reservations')
            ->join(
                'frame_reservation_items as items',
                'items.frame_reservation_id',
                '=',
                'reservations.id',
            )
            ->whereNotNull('reservations.accepted_at')
            ->count('items.id');
        $linkedReservationCount = (int) DB::table('frame_reservations as reservations')
            ->join('patients', 'patients.id', '=', 'reservations.patient_id')
            ->whereNotNull('patients.user_id')
            ->count('reservations.id');

        return [
            'reservations' => $reservationCount,
            'items' => $itemCount,
            'held_items' => $heldItemCount,
            'linked_reservations' => $linkedReservationCount,
            'unlinked_reservations' => $reservationCount - $linkedReservationCount,
        ];
    }

    private function reservationReleaseMovementCount(): int
    {
        return InventoryMovement::query()
            ->whereHas(
                'movementType',
                fn (Builder $query): Builder => $query->where('name', 'reservation_release'),
            )
            ->count();
    }
}
