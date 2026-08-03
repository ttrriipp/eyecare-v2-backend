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
    ): DispensingEvent {
        if ($jobOrder->status !== JobOrderStatus::ReadyForDispensing) {
            throw ValidationException::withMessages([
                'job_order' => ['Only ready-for-dispensing job orders can be dispensed.'],
            ]);
        }

        return DB::transaction(function () use ($jobOrder, $dispenser, $recipientName, $notes, $pickupPaymentAmount, $pickupPaymentMethod, $pickupPaymentReference): DispensingEvent {
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

            // Update job order status to dispensed
            $this->updateJobOrderStatus->handle($jobOrder, 'dispensed');

            // Record dispensing event
            $event = DispensingEvent::query()->create([
                'job_order_id' => $jobOrder->id,
                'billing_record_id' => $billingRecord->id,
                'dispensed_by' => $dispenser->id,
                'recipient_name' => $recipientName,
                'notes' => $notes,
            ]);

            // Record optional pickup payment
            if ($pickupPaymentAmount !== null && $pickupPaymentAmount > 0) {
                app(RecordBillingPayment::class)->handle(
                    billingRecord: $billingRecord,
                    amount: $pickupPaymentAmount,
                    paymentMethod: $pickupPaymentMethod ?? 'cash',
                    recorder: $dispenser,
                    referenceNumber: $pickupPaymentReference,
                    notes: 'Payment at dispensing',
                );
            }

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
