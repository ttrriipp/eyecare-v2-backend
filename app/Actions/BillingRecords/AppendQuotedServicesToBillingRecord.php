<?php

namespace App\Actions\BillingRecords;

use App\Enums\BillingItemSourceKind;
use App\Enums\TransactionItemType;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\QuotationItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppendQuotedServicesToBillingRecord
{
    /**
     * Snapshot selected quoted Service items into a Billing Record idempotently.
     *
     * Only Service items from the Quotation are accepted.
     * Repeated calls add no duplicate items (keyed by quotation_item_id).
     *
     * @param  array<int, int>  $quotationItemIds  IDs of Quotation Items to snapshot
     */
    public function handle(
        BillingRecord $billingRecord,
        array $quotationItemIds,
    ): void {
        if (empty($quotationItemIds)) {
            return;
        }

        DB::transaction(function () use ($billingRecord, $quotationItemIds) {
            // Get existing quotation item IDs (idempotent)
            $existingItemIds = $billingRecord->items()
                ->whereNotNull('quotation_item_id')
                ->pluck('quotation_item_id')
                ->toArray();

            $newItemIds = array_diff($quotationItemIds, $existingItemIds);

            if ($newItemIds === []) {
                return;
            }

            // Validate no posted payments (charge set lock) only when a new
            // service charge would actually be added.
            if ($billingRecord->payments()->where('status', 'posted')->exists()) {
                throw ValidationException::withMessages([
                    'billing_record' => ['Cannot add items to a Billing Record with posted payments.'],
                ]);
            }

            // Load the quotation items
            $items = QuotationItem::query()
                ->whereIn('id', $newItemIds)
                ->get();

            foreach ($items as $item) {
                // Validate it's a Service item
                if ($item->item_type !== TransactionItemType::Service) {
                    throw ValidationException::withMessages([
                        'items' => ["Item {$item->id} is not a Service item."],
                    ]);
                }

                BillingRecordItem::create([
                    'billing_record_id' => $billingRecord->id,
                    'item_type' => $item->item_type,
                    'source_kind' => BillingItemSourceKind::Quotation,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'job_order_item_id' => null,
                    'quotation_item_id' => $item->id,
                    'encounter_id' => null,
                ]);
            }

            // Recalculate totals
            $billingRecord->refresh();
            app(RecalculateBillingRecordTotals::class)->handle(
                $billingRecord,
                discountAmount: (float) $billingRecord->discount_amount,
            );
        });
    }
}
