<?php

namespace App\Actions\JobOrders;

use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateJobOrder
{
    public function handle(Quotation $quotation, User $creator): JobOrder
    {
        if ($quotation->status !== QuotationStatus::Accepted) {
            throw ValidationException::withMessages([
                'quotation' => ['Only accepted quotations can create job orders.'],
            ]);
        }

        $latestRevision = $quotation->latestRevision;

        if ($latestRevision === null) {
            throw ValidationException::withMessages([
                'quotation' => ['Cannot create a job order from a quotation with no revisions.'],
            ]);
        }

        // Prevent duplicate job orders from the same revision
        $existingOrder = JobOrder::query()
            ->where('quotation_revision_id', $latestRevision->id)
            ->first();

        if ($existingOrder !== null) {
            throw ValidationException::withMessages([
                'quotation' => ['A job order already exists for this quotation revision.'],
            ]);
        }

        return DB::transaction(function () use ($quotation, $latestRevision): JobOrder {
            $jobOrder = JobOrder::query()->create([
                'patient_id' => $quotation->patient_id,
                'encounter_id' => $quotation->encounter_id,
                'prescription_id' => $quotation->prescription_id,
                'quotation_revision_id' => $latestRevision->id,
                'status' => JobOrderStatus::Queued,
                'total_amount' => $latestRevision->total,
            ]);

            // Snapshot items from the quotation revision
            foreach ($latestRevision->items as $item) {
                JobOrderItem::query()->create([
                    'job_order_id' => $jobOrder->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'product_variant_id' => $item->product_variant_id,
                    'lens_category_id' => $item->lens_category_id,
                ]);
            }

            return $jobOrder->load('items');
        });
    }
}
