<?php

namespace App\Actions\OpticalOrders;

use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Enums\JobOrderStatus;
use App\Models\DispensingEvent;
use App\Models\JobOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildOpticalOrder
{
    /**
     * Create a JobOrder, snapshot items, and commit inventory.
     *
     * This is the shared core used by both order-creation paths.
     * It does not handle billing — callers append charges after this returns.
     *
     * @param  Collection<int, array<string, mixed>>  $items  Prepared item snapshots
     */
    public function handle(
        int $patientId,
        ?int $encounterId,
        ?int $prescriptionId,
        ?int $quotationId,
        string $fulfillmentMode,
        bool $usesExternalSupplier,
        Collection $items,
        ?int $dispensedBy = null,
    ): JobOrder {
        return DB::transaction(function () use ($patientId, $encounterId, $prescriptionId, $quotationId, $fulfillmentMode, $usesExternalSupplier, $items, $dispensedBy): JobOrder {
            $order = JobOrder::create([
                'patient_id' => $patientId,
                'encounter_id' => $encounterId,
                'prescription_id' => $prescriptionId,
                'quotation_id' => $quotationId,
                'status' => JobOrderStatus::Queued,
                'fulfillment_mode' => $fulfillmentMode,
                'uses_external_supplier' => $usesExternalSupplier,
                'total_amount' => $items->sum('amount'),
            ]);

            foreach ($items as $item) {
                $order->items()->create($item);
            }

            app(CommitJobOrderInventory::class)->handle($order);

            if ($fulfillmentMode === 'immediate') {
                $order->update([
                    'status' => JobOrderStatus::Dispensed,
                    'started_at' => now(),
                    'dispensed_at' => now(),
                ]);

                if ($items->isNotEmpty()) {
                    DispensingEvent::create([
                        'job_order_id' => $order->id,
                        'dispensed_by' => $dispensedBy ?? auth()->id(),
                        'notes' => 'Immediate fulfillment',
                    ]);
                }
            }

            return $order->fresh();
        });
    }
}
