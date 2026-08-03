<?php

namespace App\Actions\BillingRecords;

use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\JobOrder;
use Illuminate\Validation\ValidationException;

class AppendJobOrderItemsToBillingRecord
{
    /**
     * Snapshot Job Order items into a Billing Record idempotently.
     *
     * Every Job Order item creates one matching Billing Record item.
     * Repeated calls add no duplicate items.
     * Patient/source mismatches and posted-payment locks reject the transaction.
     */
    public function handle(
        JobOrder $jobOrder,
        BillingRecord $billingRecord,
        ?float $discountAmount = null,
    ): void {
        // Validate patient ownership
        if ($jobOrder->patient_id !== $billingRecord->patient_id) {
            throw ValidationException::withMessages([
                'billing_record' => ['Patient mismatch between Job Order and Billing Record.'],
            ]);
        }

        // Validate no posted payments (charge set lock)
        if ($billingRecord->payments()->where('status', 'posted')->exists()) {
            throw ValidationException::withMessages([
                'billing_record' => ['Cannot add items to a Billing Record with posted payments.'],
            ]);
        }

        // Check for existing items from this job order (idempotent)
        $existingItemIds = $billingRecord->items()
            ->whereNotNull('job_order_item_id')
            ->pluck('job_order_item_id')
            ->toArray();

        $newItems = $jobOrder->items()
            ->whereNotIn('id', $existingItemIds)
            ->get();

        foreach ($newItems as $item) {
            BillingRecordItem::create([
                'billing_record_id' => $billingRecord->id,
                'item_type' => $item->item_type,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'amount' => $item->amount,
                'job_order_item_id' => $item->id,
                'encounter_id' => null,
            ]);
        }

        // Apply discount if provided
        if ($discountAmount !== null) {
            $billingRecord->update([
                'discount_amount' => $discountAmount,
            ]);
        }

        // Recalculate totals
        $billingRecord->refresh();
        app(RecalculateBillingRecordTotals::class)->handle(
            $billingRecord,
            discountAmount: (float) $billingRecord->discount_amount,
        );
    }
}
