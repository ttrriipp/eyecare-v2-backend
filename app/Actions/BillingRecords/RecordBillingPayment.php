<?php

namespace App\Actions\BillingRecords;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\BillingRecordStatus;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordBillingPayment
{
    public function handle(
        BillingRecord $billingRecord,
        float $amount,
        string $paymentMethod,
        User $recorder,
        ?string $referenceNumber = null,
        ?string $notes = null,
    ): BillingPayment {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Payment amount must be greater than zero.'],
            ]);
        }

        if ($billingRecord->status === BillingRecordStatus::Voided) {
            throw ValidationException::withMessages([
                'billing_record' => ['Cannot record payments against a voided billing record.'],
            ]);
        }

        return DB::transaction(function () use ($billingRecord, $amount, $paymentMethod, $recorder, $referenceNumber, $notes): BillingPayment {
            $locked = BillingRecord::query()
                ->whereKey($billingRecord->id)
                ->lockForUpdate()
                ->first();

            if ($locked->status === BillingRecordStatus::Voided) {
                throw ValidationException::withMessages([
                    'billing_record' => ['Cannot record payments against a voided billing record.'],
                ]);
            }

            $newBalance = max($locked->balance_due - $amount, 0);

            $payment = BillingPayment::query()->create([
                'billing_record_id' => $locked->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,
                'status' => 'posted',
                'recorded_by' => $recorder->id,
                'recorded_at' => now(),
                'notes' => $notes,
            ]);

            $locked->update([
                'amount_paid' => $locked->amount_paid + $amount,
                'balance_due' => $newBalance,
                'status' => $newBalance <= 0
                    ? BillingRecordStatus::Paid
                    : BillingRecordStatus::PartiallyPaid,
            ]);

            app(CreateAuditLog::class)->handle(
                subject: $locked,
                action: 'billing_record.payment_recorded',
                metadata: [
                    'payment_id' => $payment->id,
                    'amount' => $amount,
                    'payment_method' => $paymentMethod,
                ],
                actorId: $recorder->id,
            );

            return $payment;
        });
    }
}
