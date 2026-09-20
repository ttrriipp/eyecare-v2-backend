<?php

namespace App\Actions\BillingRecords;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelBillingRecord
{
    public function handle(
        BillingRecord $billingRecord,
        string $reason,
        User $canceller,
    ): BillingRecord {
        if ($billingRecord->status === BillingRecordStatus::Cancelled) {
            throw ValidationException::withMessages([
                'billing_record' => ['This billing record is already cancelled.'],
            ]);
        }

        return DB::transaction(function () use ($billingRecord, $reason, $canceller): BillingRecord {
            $locked = BillingRecord::query()
                ->whereKey($billingRecord->id)
                ->lockForUpdate()
                ->first();

            if ($locked->status === BillingRecordStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'billing_record' => ['This billing record is already cancelled.'],
                ]);
            }

            $locked->update([
                'status' => BillingRecordStatus::Cancelled,
                'cancelled_by' => $canceller->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $locked,
                action: AuditEvent::BillingRecordCancelled,
                metadata: [
                    'reason' => $reason,
                    'previous_status' => $billingRecord->status->value,
                ],
                actorId: $canceller->id,
            );

            return $locked->fresh();
        });
    }
}
