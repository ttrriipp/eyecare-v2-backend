<?php

namespace App\Actions\Billing;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Billing;
use App\Notifications\BillingIssued;

class CreateServiceBilling
{
    public function __construct(
        private readonly GetOrCreateBilling $getOrCreate,
        private readonly AddServiceToBilling $addService,
    ) {}

    /**
     * Find or create a billing for this customer + appointment, then add a service item.
     *
     * @param  array<string, mixed>  $data  Keys: customer_id, service_id, staff_id, amount (optional), performed_at, appointment_id (optional)
     */
    public function handle(array $data): Billing
    {
        $billing = $this->getOrCreate->handle(
            customerId: $data['customer_id'],
            appointmentId: $data['appointment_id'] ?? null,
        );

        $isNewBilling = $billing->wasRecentlyCreated;

        $this->addService->handle($billing, $data);

        $billing->refresh();

        app(CreateAuditLog::class)->handle(
            subject: $billing,
            action: 'billing.service_added',
            metadata: ['customer_id' => $data['customer_id'], 'service_id' => $data['service_id']],
        );

        if ($isNewBilling) {
            $billing->customer->notify(new BillingIssued($billing));
        }

        return $billing;
    }
}
