<?php

namespace App\Actions\BillingRecords;

use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecalculateBillingRecordTotals
{
    /**
     * Recalculate Billing Record totals from its item snapshots.
     *
     * Totals derive exclusively from Billing Record items and approved
     * discount input. Existing posted payments are preserved.
     */
    public function handle(
        BillingRecord $billingRecord,
        ?float $discountAmount = null,
    ): BillingRecord {
        if ($billingRecord->status === BillingRecordStatus::Voided) {
            throw ValidationException::withMessages([
                'billing_record' => ['Cannot modify a voided billing record.'],
            ]);
        }

        return DB::transaction(function () use ($billingRecord, $discountAmount) {
            $locked = BillingRecord::query()
                ->whereKey($billingRecord->id)
                ->lockForUpdate()
                ->firstOrFail();

            $subtotal = (float) $locked->items()->sum('amount');

            if ($discountAmount !== null) {
                if ($discountAmount < 0) {
                    throw ValidationException::withMessages([
                        'discount_amount' => ['Discount cannot be negative.'],
                    ]);
                }

                if ($discountAmount > $subtotal) {
                    throw ValidationException::withMessages([
                        'discount_amount' => ['Discount cannot exceed subtotal.'],
                    ]);
                }
            }

            $discount = $discountAmount ?? (float) $locked->discount_amount;
            $total = max($subtotal - $discount, 0);
            $amountPaid = (float) $locked->amount_paid;
            $balanceDue = max($total - $amountPaid, 0);

            $status = $this->calculateStatus($amountPaid, $balanceDue, $locked->status);

            $locked->update([
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'balance_due' => $balanceDue,
                'status' => $status,
            ]);

            return $locked->fresh();
        });
    }

    private function calculateStatus(float $amountPaid, float $balanceDue, BillingRecordStatus $currentStatus): BillingRecordStatus
    {
        if ($currentStatus === BillingRecordStatus::Voided) {
            return BillingRecordStatus::Voided;
        }

        if ($balanceDue <= 0 && $amountPaid > 0) {
            return BillingRecordStatus::Paid;
        }

        if ($amountPaid > 0) {
            return BillingRecordStatus::PartiallyPaid;
        }

        return BillingRecordStatus::Unpaid;
    }
}
