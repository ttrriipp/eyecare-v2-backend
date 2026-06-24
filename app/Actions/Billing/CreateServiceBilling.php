<?php

namespace App\Actions\Billing;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\User;
use App\Notifications\BillingIssued;

class CreateServiceBilling
{
    public function __construct(
        private readonly AddServiceToBilling $addService,
    ) {}

    /**
     * Create a new standalone billing and add a service line item.
     *
     * @param  array<string, mixed>  $data  Keys: customer_id, service_id, staff_id, amount (optional), performed_at, appointment_id (optional)
     */
    public function handle(array $data): Billing
    {
        $issuedStatus = BillingStatus::query()->where('name', 'issued')->firstOrFail();

        $billing = Billing::query()->create([
            'customer_id' => $data['customer_id'],
            'order_id' => null,
            'billing_status_id' => $issuedStatus->id,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'total_amount' => '0.00',
            'amount_paid' => '0.00',
            'balance_due' => '0.00',
            'issued_at' => now(),
        ]);

        $this->addService->handle($billing, $data);

        $billing->refresh();

        app(CreateAuditLog::class)->handle(
            subject: $billing,
            action: 'billing.generated',
            metadata: ['customer_id' => $data['customer_id']],
        );

        /** @var User $customer */
        $customer = $billing->customer;
        $customer->notify(new BillingIssued($billing));

        return $billing;
    }
}
