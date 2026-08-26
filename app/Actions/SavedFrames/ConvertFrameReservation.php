<?php

namespace App\Actions\SavedFrames;

use App\Models\InventoryMovementType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class ConvertFrameReservation
{
    /**
     * Convert one legacy reservation inside a single transaction.
     *
     * @return array{
     *     released_items: int,
     *     saved_frames_created: int,
     *     saved_frames_updated: int,
     *     skipped_unlinked_items: int,
     * }
     */
    public function handle(int $reservationId): array
    {
        return DB::transaction(function () use ($reservationId): array {
            $reservation = DB::table('frame_reservations')
                ->where('id', $reservationId)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return [
                    'released_items' => 0,
                    'saved_frames_created' => 0,
                    'saved_frames_updated' => 0,
                    'skipped_unlinked_items' => 0,
                ];
            }

            $items = DB::table('frame_reservation_items')
                ->where('frame_reservation_id', $reservationId)
                ->orderBy('product_variant_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $variantIds = $items
                ->pluck('product_variant_id')
                ->map(fn (mixed $variantId): int => (int) $variantId)
                ->unique()
                ->sort()
                ->values();

            $variants = [];

            foreach ($variantIds as $variantId) {
                $variant = DB::table('product_variants')
                    ->where('id', $variantId)
                    ->lockForUpdate()
                    ->first();

                if ($variant === null) {
                    throw new \RuntimeException(
                        "Cannot convert reservation #{$reservationId}: product variant #{$variantId} no longer exists.",
                    );
                }

                $variants[$variantId] = $variant;
            }

            $userId = DB::table('patients')
                ->where('id', $reservation->patient_id)
                ->value('user_id');
            $releaseMovementTypeId = $reservation->accepted_at === null
                ? null
                : InventoryMovementType::query()
                    ->firstOrCreate(['name' => 'reservation_release'])
                    ->id;

            $releasedItems = 0;
            $savedFramesCreated = 0;
            $savedFramesUpdated = 0;
            $skippedUnlinkedItems = 0;

            foreach ($items as $item) {
                $variantId = (int) $item->product_variant_id;

                if ($releaseMovementTypeId !== null) {
                    $variant = $variants[$variantId];
                    $previousStock = (int) $variant->stock_quantity;
                    $newStock = $previousStock + 1;

                    DB::table('product_variants')
                        ->where('id', $variantId)
                        ->update([
                            'stock_quantity' => $newStock,
                            'updated_at' => now(),
                        ]);

                    DB::table('inventory_movements')->insert([
                        'product_variant_id' => $variantId,
                        'reservation_id' => $reservationId,
                        'inventory_movement_type_id' => $releaseMovementTypeId,
                        'quantity_change' => 1,
                        'previous_stock' => $previousStock,
                        'new_stock' => $newStock,
                        'created_by' => null,
                        'notes' => "Release for reservation #{$reservationId}",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $variant->stock_quantity = $newStock;
                    $releasedItems++;
                }

                if ($userId === null) {
                    $skippedUnlinkedItems++;

                    continue;
                }

                $savedAt = Carbon::parse(
                    $item->created_at ?? $reservation->created_at ?? now(),
                )->toDateTimeString();
                $existing = DB::table('saved_frames')
                    ->where('user_id', $userId)
                    ->where('product_variant_id', $variantId)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    DB::table('saved_frames')->insert([
                        'user_id' => $userId,
                        'product_variant_id' => $variantId,
                        'created_at' => $savedAt,
                        'updated_at' => now(),
                    ]);
                    $savedFramesCreated++;

                    continue;
                }

                if (Carbon::parse($existing->created_at)->greaterThan(Carbon::parse($savedAt))) {
                    DB::table('saved_frames')
                        ->where('id', $existing->id)
                        ->update(['created_at' => $savedAt]);
                    $savedFramesUpdated++;
                }
            }

            DB::table('frame_reservation_items')
                ->where('frame_reservation_id', $reservationId)
                ->delete();
            DB::table('frame_reservations')
                ->where('id', $reservationId)
                ->delete();

            return [
                'released_items' => $releasedItems,
                'saved_frames_created' => $savedFramesCreated,
                'saved_frames_updated' => $savedFramesUpdated,
                'skipped_unlinked_items' => $skippedUnlinkedItems,
            ];
        });
    }
}
