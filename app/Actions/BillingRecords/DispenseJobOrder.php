<?php

namespace App\Actions\BillingRecords;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Models\BillingRecord;
use App\Models\DispensingEvent;
use App\Models\JobOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DispenseJobOrder
{
    public function __construct(
        private readonly UpdateJobOrderStatus $updateJobOrderStatus,
    ) {}

    /**
     * Dispense a job order against its existing billing record.
     *
     * The billing record must exist (created at confirmation). Dispensing
     * never creates a new billing record. Optional pickup payment is
     * recorded against the existing billing record.
     */
    public function handle(
        JobOrder $jobOrder,
        User $dispenser,
        ?string $recipientName = null,
        ?string $notes = null,
        ?float $pickupPaymentAmount = null,
        ?string $pickupPaymentMethod = null,
        ?string $pickupPaymentReference = null,
        ?bool $adminOverride = false,
        ?string $overrideReason = null,
        ?string $overrideDueDate = null,
    ): DispensingEvent {
        if ($jobOrder->status !== JobOrderStatus::ReadyForDispensing) {
            throw ValidationException::withMessages([
                'job_order' => ['Only ready-for-dispensing job orders can be dispensed.'],
            ]);
        }

        return DB::transaction(function () use ($jobOrder, $dispenser, $recipientName, $notes, $pickupPaymentAmount, $pickupPaymentMethod, $pickupPaymentReference, $adminOverride, $overrideReason, $overrideDueDate): DispensingEvent {
            // Require existing active billing record (created at confirmation)
            $billingRecord = BillingRecord::query()
                ->where('job_order_id', $jobOrder->id)
                ->where('status', '!=', BillingRecordStatus::Voided)
                ->lockForUpdate()
                ->first();

            if ($billingRecord === null) {
                throw ValidationException::withMessages([
                    'job_order' => ['No billing record found. Confirm the sale before dispensing.'],
                ]);
            }

            if ($billingRecord->status === BillingRecordStatus::Voided) {
                throw ValidationException::withMessages([
                    'billing_record' => ['Cannot dispense against a voided billing record.'],
                ]);
            }

            // Record pickup payment first (if any) to clear balance
            if ($pickupPaymentAmount !== null && $pickupPaymentAmount > 0) {
                app(RecordBillingPayment::class)->handle(
                    billingRecord: $billingRecord,
                    amount: $pickupPaymentAmount,
                    paymentMethod: $pickupPaymentMethod ?? 'cash',
                    recorder: $dispenser,
                    referenceNumber: $pickupPaymentReference,
                    notes: 'Payment at dispensing',
                    chargesReviewed: true,
                );

                $billingRecord->refresh();
            }

            // Routine dispensing requires zero balance
            if ((float) $billingRecord->balance_due > 0) {
                if ($adminOverride && $dispenser->isAdmin()) {
                    // Admin override: require reason and due date
                    if (blank($overrideReason)) {
                        throw ValidationException::withMessages([
                            'override_reason' => ['Provide a reason for releasing with an outstanding balance.'],
                        ]);
                    }

                    if (blank($overrideDueDate)) {
                        throw ValidationException::withMessages([
                            'override_due_date' => ['Provide a payment due date for the outstanding balance.'],
                        ]);
                    }
                } else {
                    throw ValidationException::withMessages([
                        'billing_record' => ['The billing record has an outstanding balance of '.number_format((float) $billingRecord->balance_due, 2).'. Record full payment before dispensing.'],
                    ]);
                }
            }

            // Update job order status to dispensed
            $this->updateJobOrderStatus->handle($jobOrder, 'dispensed');

            // Record dispensing event
            $event = DispensingEvent::query()->create([
                'job_order_id' => $jobOrder->id,
                'billing_record_id' => $billingRecord->id,
                'dispensed_by' => $dispenser->id,
                'recipient_name' => $recipientName,
                'notes' => $notes,
                'released_balance_amount' => (float) $billingRecord->balance_due,
                'balance_override_by' => $adminOverride ? $dispenser->id : null,
                'balance_override_reason' => $adminOverride ? $overrideReason : null,
                'balance_due_date' => $adminOverride ? $overrideDueDate : null,
            ]);

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $billingRecord,
                action: 'billing_record.dispensed',
                metadata: [
                    'job_order_id' => $jobOrder->id,
                    'dispensing_event_id' => $event->id,
                    'pickup_payment' => $pickupPaymentAmount,
                ],
                actorId: $dispenser->id,
            );

            return $event;
        });
    }
}
