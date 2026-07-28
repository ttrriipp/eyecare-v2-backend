<?php

namespace App\Actions\BillingRecords;

use App\Actions\Audit\CreateAuditLog;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectBillingPayment
{
    public function handle(
        BillingPayment $originalPayment,
        float $newAmount,
        string $reason,
        User $corrector,
        ?string $newReferenceNumber = null,
    ): BillingPayment {
        if ($originalPayment->status !== 'posted') {
            throw ValidationException::withMessages([
                'payment' => ['Only posted payments can be corrected.'],
            ]);
        }

        if ($newAmount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Replacement amount must be greater than zero.'],
            ]);
        }

        return DB::transaction(function () use ($originalPayment, $newAmount, $reason, $corrector, $newReferenceNumber): BillingPayment {
            // Lock the billing record
            $billingRecord = BillingRecord::query()
                ->whereKey($originalPayment->billing_record_id)
                ->lockForUpdate()
                ->first();

            // Reverse the original payment
            $originalPayment->update([
                'status' => 'reversed',
                'reversed_by' => $corrector->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ]);

            // Create replacement payment
            $replacement = BillingPayment::query()->create([
                'billing_record_id' => $billingRecord->id,
                'amount' => $newAmount,
                'payment_method' => $originalPayment->payment_method,
                'reference_number' => $newReferenceNumber ?? $originalPayment->reference_number,
                'status' => 'posted',
                'recorded_by' => $corrector->id,
                'recorded_at' => now(),
                'notes' => "Correction of payment #{$originalPayment->id}: {$reason}",
            ]);

            // Recalculate balance
            $postedTotal = $billingRecord->postedPayments()->sum('amount');
            $billingRecord->update([
                'amount_paid' => $postedTotal,
                'balance_due' => max($billingRecord->total_amount - $postedTotal, 0),
                'status' => $postedTotal <= 0
                    ? \App\Enums\BillingRecordStatus::Unpaid
                    : ($postedTotal >= $billingRecord->total_amount
                        ? \App\Enums\BillingRecordStatus::Paid
                        : \App\Enums\BillingRecordStatus::PartiallyPaid),
            ]);

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $billingRecord,
                action: 'billing_record.payment_corrected',
                metadata: [
                    'original_payment_id' => $originalPayment->id,
                    'replacement_payment_id' => $replacement->id,
                    'original_amount' => $originalPayment->amount,
                    'new_amount' => $newAmount,
                    'reason' => $reason,
                ],
                actorId: $corrector->id,
            );

            return $replacement;
        });
    }
}
