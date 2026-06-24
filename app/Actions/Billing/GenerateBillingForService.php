<?php

namespace App\Actions\Billing;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\ServiceRecord;
use Illuminate\Validation\ValidationException;

class GenerateBillingForService
{
    /**
     * Generate a billing record for a service record.
     *
     * Throws a ValidationException if a billing already exists for this service record.
     */
    public function handle(ServiceRecord $serviceRecord): Billing
    {
        if ($serviceRecord->billing()->exists()) {
            throw ValidationException::withMessages([
                'service_record' => ['A billing record already exists for this service record.'],
            ]);
        }

        $issuedStatus = BillingStatus::query()->where('name', 'issued')->firstOrFail();

        $billing = Billing::query()->create([
            'billable_type' => ServiceRecord::class,
            'billable_id' => $serviceRecord->id,
            'billing_status_id' => $issuedStatus->id,
            'total_amount' => $serviceRecord->total_amount,
            'amount_paid' => '0.00',
            'balance_due' => $serviceRecord->total_amount,
            'issued_at' => now(),
        ]);

        app(CreateAuditLog::class)->handle(
            subject: $billing,
            action: 'billing.generated',
            metadata: ['service_record_id' => $serviceRecord->id, 'total_amount' => (string) $serviceRecord->total_amount],
        );

        return $billing;
    }
}
