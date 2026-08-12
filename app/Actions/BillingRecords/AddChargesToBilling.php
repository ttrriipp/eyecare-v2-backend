<?php

namespace App\Actions\BillingRecords;

use App\Enums\BillingItemSourceKind;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddChargesToBilling
{
    /**
     * Append charge lines to a billing record and recalculate totals.
     *
     * One append path keyed by source kind, replacing five actions that
     * differed only in where their charge lines came from.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     */
    public function handle(
        BillingRecord $billingRecord,
        BillingItemSourceKind $sourceKind,
        Collection $items,
        ?float $discountAmount = null,
    ): BillingRecord {
        if ($items->isEmpty()) {
            return $billingRecord;
        }

        return DB::transaction(function () use ($billingRecord, $sourceKind, $items, $discountAmount) {
            $locked = BillingRecord::query()
                ->whereKey($billingRecord->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->payments()->where('status', 'posted')->exists()) {
                throw ValidationException::withMessages([
                    'billing_record' => ['Cannot add items to a Billing Record with posted payments.'],
                ]);
            }

            foreach ($items as $item) {
                BillingRecordItem::create([
                    'billing_record_id' => $locked->id,
                    'item_type' => $item['item_type'],
                    'source_kind' => $sourceKind,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'amount' => $item['amount'],
                    'job_order_item_id' => $item['job_order_item_id'] ?? null,
                    'quotation_item_id' => $item['quotation_item_id'] ?? null,
                    'encounter_id' => $item['encounter_id'] ?? null,
                    'service_id' => $item['service_id'] ?? null,
                ]);
            }

            if ($discountAmount !== null) {
                $locked->update(['discount_amount' => $discountAmount]);
            }

            $locked->refresh();

            app(RecalculateBillingRecordTotals::class)->handle(
                $locked,
                discountAmount: $discountAmount ?? (float) $locked->discount_amount,
            );

            return $locked->fresh();
        });
    }
}
