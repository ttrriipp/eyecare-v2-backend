<?php

namespace App\Actions\Billing;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\Service;
use App\Models\ServiceRecord;
use Illuminate\Validation\ValidationException;

class AddServiceToBilling
{
    /**
     * Add a service line item to an existing billing.
     * Creates a ServiceRecord as the audit trail, then a BillingItem.
     *
     * @param  array<string, mixed>  $data  Keys: service_id, staff_id, amount (optional override), performed_at, appointment_id (optional)
     */
    public function handle(Billing $billing, array $data): BillingItem
    {
        if ($billing->status->name === 'voided') {
            throw ValidationException::withMessages([
                'billing' => ['Cannot add items to a voided billing.'],
            ]);
        }

        $service = Service::query()->findOrFail($data['service_id']);
        $amount = $data['amount'] ?? $service->price;

        $serviceRecord = ServiceRecord::query()->create([
            'customer_id' => $billing->customer_id,
            'service_id' => $service->id,
            'appointment_id' => $data['appointment_id'] ?? null,
            'staff_id' => $data['staff_id'],
            'amount' => $amount,
            'notes' => $data['notes'] ?? null,
            'performed_at' => $data['performed_at'] ?? now(),
        ]);

        $billingItem = BillingItem::query()->create([
            'billing_id' => $billing->id,
            'type' => 'service',
            'description' => $service->name,
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
            'service_record_id' => $serviceRecord->id,
        ]);

        // Recalculate billing totals
        $newSubtotal = $billing->items()->sum('amount');
        $discountAmount = $billing->discount_amount ?? '0.00';
        $newTotal = bcsub((string) $newSubtotal, (string) $discountAmount, 2);

        $billing->update([
            'subtotal' => $newSubtotal,
            'total_amount' => $newTotal,
            'balance_due' => bcsub((string) $newTotal, (string) $billing->amount_paid, 2),
        ]);

        app(CreateAuditLog::class)->handle(
            subject: $billing,
            action: 'billing.service_added',
            metadata: ['service' => $service->name, 'amount' => (string) $amount],
        );

        return $billingItem;
    }
}
