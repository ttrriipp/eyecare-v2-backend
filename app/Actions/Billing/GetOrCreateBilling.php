<?php

namespace App\Actions\Billing;

use App\Models\Billing;
use App\Models\BillingStatus;

class GetOrCreateBilling
{
    /**
     * Find an existing non-voided billing for the given customer + appointment,
     * or create a new issued billing if none exists.
     *
     * If appointment_id is null, always creates a new billing (no grouping).
     */
    public function handle(int $customerId, ?int $appointmentId = null): Billing
    {
        if ($appointmentId !== null) {
            $existing = Billing::query()
                ->where('customer_id', $customerId)
                ->where('appointment_id', $appointmentId)
                ->whereHas('status', fn ($q) => $q->where('name', '!=', 'voided'))
                ->latest()
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $issuedStatus = BillingStatus::query()->where('name', 'issued')->firstOrFail();

        return Billing::query()->create([
            'customer_id' => $customerId,
            'appointment_id' => $appointmentId,
            'order_id' => null,
            'billing_status_id' => $issuedStatus->id,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'total_amount' => '0.00',
            'amount_paid' => '0.00',
            'balance_due' => '0.00',
            'issued_at' => now(),
        ]);
    }
}
