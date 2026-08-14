<?php

namespace App\Actions\BillingRecords;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\BillingItemSourceKind;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\User;
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
        ?User $actor = null,
    ): BillingRecord {
        if ($items->isEmpty()) {
            return $billingRecord;
        }

        return DB::transaction(function () use ($billingRecord, $sourceKind, $items, $discountAmount, $actor) {
            $locked = BillingRecord::query()
                ->whereKey($billingRecord->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->payments()->where('status', 'posted')->exists()) {
                throw ValidationException::withMessages([
                    'billing_record' => ['Cannot add items to a Billing Record with posted payments.'],
                ]);
            }

            $previousDiscount = (float) $locked->discount_amount;
            $previousTotal = (float) $locked->total_amount;

            foreach ($items as $item) {
                BillingRecordItem::create([
                    'billing_record_id' => $locked->id,
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

            $recalculated = app(RecalculateBillingRecordTotals::class)->handle(
                $locked,
                discountAmount: $discountAmount ?? (float) $locked->discount_amount,
            );

            $auditActorId = $actor?->id ?? auth()->id();

            app(CreateAuditLog::class)->handle(
                subject: $recalculated,
                action: AuditEvent::BillingChargesAdded,
                metadata: [
                    'source_kind' => $sourceKind->value,
                    'line_count' => $items->count(),
                    'amount' => (float) $items->sum(fn (array $item): float => (float) $item['amount']),
                    'previous_total' => $previousTotal,
                    'total' => (float) $recalculated->total_amount,
                ],
                actorId: $auditActorId,
            );

            if ($discountAmount !== null && $previousDiscount !== (float) $recalculated->discount_amount) {
                app(CreateAuditLog::class)->handle(
                    subject: $recalculated,
                    action: AuditEvent::BillingDiscountChanged,
                    metadata: [
                        'previous_discount_amount' => $previousDiscount,
                        'discount_amount' => (float) $recalculated->discount_amount,
                    ],
                    actorId: $auditActorId,
                );
            }

            return $recalculated;
        });
    }
}
