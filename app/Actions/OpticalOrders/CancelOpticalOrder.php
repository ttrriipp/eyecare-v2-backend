<?php

namespace App\Actions\OpticalOrders;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Enums\AuditEvent;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelOpticalOrder
{
    public function handle(
        JobOrder $jobOrder,
        ?string $reason = null,
        ?User $actor = null,
    ): array {
        return DB::transaction(function () use ($jobOrder, $reason, $actor) {
            $lockedJobOrder = JobOrder::query()
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);

            if ($lockedJobOrder->status === JobOrderStatus::Cancelled
                || $lockedJobOrder->status === JobOrderStatus::Dispensed) {
                throw ValidationException::withMessages([
                    'job_order' => ['This order cannot be cancelled.'],
                ]);
            }

            // Reverse inventory
            app(UpdateJobOrderStatus::class)->handle(
                jobOrder: $lockedJobOrder,
                statusName: JobOrderStatus::Cancelled->value,
                actor: $actor,
            );

            // Handle billing - use active (non-voided) record
            $billingRecord = $lockedJobOrder->activeBillingRecord;
            $hasPostedPayments = false;

            if ($billingRecord !== null) {
                $hasPostedPayments = $billingRecord->payments()
                    ->where('status', 'posted')
                    ->exists();

                if (! $hasPostedPayments) {
                    // No posted payments - void the billing record
                    $previousStatus = $billingRecord->status->value;
                    $billingRecord->update([
                        'status' => BillingRecordStatus::Voided,
                        'voided_by' => $actor?->id ?? auth()->id(),
                        'voided_at' => now(),
                        'void_reason' => $reason,
                    ]);

                    app(CreateAuditLog::class)->handle(
                        subject: $billingRecord,
                        action: AuditEvent::BillingRecordVoided,
                        metadata: [
                            'previous_status' => $previousStatus,
                            'triggered_by_job_order_id' => $lockedJobOrder->id,
                            'reason_provided' => filled($reason),
                        ],
                        actorId: $actor?->id ?? auth()->id(),
                    );
                }
                // If there are posted payments, billing record stays as-is
                // Staff must handle reversal/refund explicitly
            }

            return [
                'job_order' => $lockedJobOrder->fresh(),
                'billing_record' => $billingRecord?->fresh(),
                'has_posted_payments' => $hasPostedPayments,
            ];
        });
    }
}
