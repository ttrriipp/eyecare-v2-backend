<?php

namespace App\Actions\OpticalOrders;

use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelOpticalOrder
{
    public function handle(
        JobOrder $jobOrder,
        ?string $reason = null,
    ): array {
        if ($jobOrder->status === JobOrderStatus::Cancelled
            || $jobOrder->status === JobOrderStatus::Dispensed) {
            throw ValidationException::withMessages([
                'job_order' => ['This order cannot be cancelled.'],
            ]);
        }

        return DB::transaction(function () use ($jobOrder) {
            // Reverse inventory
            app(UpdateJobOrderStatus::class)->handle(
                jobOrder: $jobOrder,
                newStatus: JobOrderStatus::Cancelled,
            );

            // Handle billing
            $billingRecord = $jobOrder->billingRecord;
            $hasPostedPayments = false;

            if ($billingRecord !== null) {
                $hasPostedPayments = $billingRecord->payments()
                    ->where('status', 'posted')
                    ->exists();

                if (! $hasPostedPayments) {
                    // No posted payments - void the billing record
                    $billingRecord->update([
                        'status' => BillingRecordStatus::Voided,
                    ]);
                }
                // If there are posted payments, billing record stays as-is
                // Staff must handle reversal/refund explicitly
            }

            return [
                'job_order' => $jobOrder->fresh(),
                'billing_record' => $billingRecord?->fresh(),
                'has_posted_payments' => $hasPostedPayments,
            ];
        });
    }
}
