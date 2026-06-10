<?php

namespace App\Actions\Billing;

use App\Models\Billing;
use App\Models\BillingStatus;

class RecalculateBillingBalance
{
    /**
     * Recalculate billing balance from posted payments only (voided and reversed are excluded),
     * then update billing status to reflect the current payment state.
     */
    public function handle(Billing $billing): Billing
    {
        $amountPaid = $billing->payments()
            ->whereHas('status', fn ($q) => $q->where('name', 'posted'))
            ->sum('amount');

        $balanceDue = bcsub((string) $billing->total_amount, (string) $amountPaid, 2);

        $newStatusName = $this->resolveStatusName($billing, (float) $balanceDue, (float) $amountPaid);
        $newStatus = BillingStatus::query()->where('name', $newStatusName)->firstOrFail();

        $billing->update([
            'amount_paid' => $amountPaid,
            'balance_due' => $balanceDue,
            'billing_status_id' => $newStatus->id,
        ]);

        return $billing->fresh(['status']);
    }

    /**
     * Resolve the correct billing status name based on the current balance and amount paid.
     */
    private function resolveStatusName(Billing $billing, float $balanceDue, float $amountPaid): string
    {
        if ($balanceDue <= 0) {
            return 'paid';
        }

        if ($amountPaid > 0) {
            return 'partially_paid';
        }

        return $billing->status->name;
    }
}
