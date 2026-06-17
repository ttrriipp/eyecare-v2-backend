<?php

namespace App\Actions\Billing;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Billing;
use App\Models\Payment;
use App\Models\PaymentStatus;

class RecordPayment
{
    public function __construct(
        private readonly RecalculateBillingBalance $recalculate,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Billing $billing, array $data): Payment
    {
        $postedStatus = PaymentStatus::query()->where('name', 'posted')->firstOrFail();

        $payment = $billing->payments()->create([
            'payment_status_id' => $postedStatus->id,
            'amount' => $data['amount'],
            'method' => $data['method'],
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'paid_at' => $data['paid_at'] ?? now(),
        ]);

        $this->recalculate->handle($billing);

        app(CreateAuditLog::class)->handle(
            subject: $billing,
            action: 'billing.payment_recorded',
            metadata: ['payment_id' => $payment->id, 'amount' => (string) $data['amount']],
        );

        return $payment;
    }
}
