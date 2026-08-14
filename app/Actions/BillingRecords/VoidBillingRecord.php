<?php

namespace App\Actions\BillingRecords;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidBillingRecord
{
    public function handle(
        BillingRecord $billingRecord,
        string $reason,
        User $voider,
    ): BillingRecord {
        if ($billingRecord->status === BillingRecordStatus::Voided) {
            throw ValidationException::withMessages([
                'billing_record' => ['This billing record is already voided.'],
            ]);
        }

        return DB::transaction(function () use ($billingRecord, $reason, $voider): BillingRecord {
            $locked = BillingRecord::query()
                ->whereKey($billingRecord->id)
                ->lockForUpdate()
                ->first();

            if ($locked->status === BillingRecordStatus::Voided) {
                throw ValidationException::withMessages([
                    'billing_record' => ['This billing record is already voided.'],
                ]);
            }

            $locked->update([
                'status' => BillingRecordStatus::Voided,
                'voided_by' => $voider->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $locked,
                action: AuditEvent::BillingRecordVoided,
                metadata: [
                    'reason' => $reason,
                    'previous_status' => $billingRecord->status->value,
                ],
                actorId: $voider->id,
            );

            return $locked->fresh();
        });
    }
}
