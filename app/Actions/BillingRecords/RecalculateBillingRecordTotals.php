<?php

namespace App\Actions\BillingRecords;

use App\Enums\BillingRecordStatus;
use App\Enums\DiscountType;
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
        if ($billingRecord->status === BillingRecordStatus::Cancelled) {
            throw ValidationException::withMessages([
                'billing_record' => ['Cannot modify a cancelled billing record.'],
            ]);
        }

        return DB::transaction(function () use ($billingRecord, $discountAmount) {
            $locked = BillingRecord::query()
                ->whereKey($billingRecord->id)
                ->lockForUpdate()
                ->firstOrFail();

            $subtotal = (float) $locked->items()->sum('amount');

            $vatSummary = null;

            if ($locked->vat_calculation_version === 1) {
                $discountType = $locked->discount_type ?? DiscountType::None;
                $items = $locked->items()
                    ->get(['amount', 'vat_treatment', 'statutory_discount_eligible']);

                if ($discountType->isStatutory()) {
                    if (! $locked->discount_eligibility_verified) {
                        throw ValidationException::withMessages([
                            'discount_eligibility_verified' => ['Verify the patient’s entitlement and exclusive use before applying this discount.'],
                        ]);
                    }

                    if (! $items->contains(fn ($item): bool => (bool) $item->statutory_discount_eligible)) {
                        throw ValidationException::withMessages([
                            'discount_type' => ['At least one legally qualifying line is required for a statutory discount.'],
                        ]);
                    }
                }

                $vatSummary = app(CalculatePhilippineVatSummary::class)->handle(
                    items: $items
                        ->map(fn ($item): array => [
                            'amount' => $item->amount,
                            'vat_treatment' => $item->vat_treatment,
                            'statutory_discount_eligible' => $item->statutory_discount_eligible,
                        ])
                        ->all(),
                    discountType: $discountType,
                    discountAmount: $discountAmount ?? (float) $locked->discount_amount,
                );

                $subtotal = $vatSummary['subtotal_cents'] / 100;
            }

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

            $discount = $vatSummary === null
                ? ($discountAmount ?? (float) $locked->discount_amount)
                : $vatSummary['discount_cents'] / 100;
            $total = $vatSummary === null
                ? max($subtotal - $discount, 0)
                : $vatSummary['total_cents'] / 100;
            $amountPaid = (float) $locked->amount_paid;
            $balanceDue = max($total - $amountPaid, 0);

            $status = $this->calculateStatus($amountPaid, $balanceDue, $locked->status);

            $updates = [
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'balance_due' => $balanceDue,
                'status' => $status,
            ];

            if ($vatSummary !== null) {
                $updates += [
                    'vatable_sales_amount' => $vatSummary['vatable_sales_cents'] / 100,
                    'vat_amount' => $vatSummary['vat_amount_cents'] / 100,
                    'vat_exempt_sales_amount' => $vatSummary['vat_exempt_sales_cents'] / 100,
                    'zero_rated_sales_amount' => $vatSummary['zero_rated_sales_cents'] / 100,
                    'vat_exemption_amount' => $vatSummary['vat_exemption_cents'] / 100,
                ];
            }

            $locked->update($updates);

            return $locked->fresh();
        });
    }

    private function calculateStatus(float $amountPaid, float $balanceDue, BillingRecordStatus $currentStatus): BillingRecordStatus
    {
        if ($currentStatus === BillingRecordStatus::Cancelled) {
            return BillingRecordStatus::Cancelled;
        }

        if ($balanceDue <= 0) {
            return BillingRecordStatus::Paid;
        }

        if ($amountPaid > 0) {
            return BillingRecordStatus::PartiallyPaid;
        }

        return BillingRecordStatus::Unpaid;
    }
}
