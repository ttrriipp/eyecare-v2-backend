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
     * Atomically dispense a job order, create billing record, and record dispensing event.
     */
    public function handle(
        JobOrder $jobOrder,
        User $dispenser,
        ?string $recipientName = null,
        ?string $notes = null,
        ?float $initialPaymentAmount = null,
        ?string $initialPaymentMethod = null,
    ): DispensingEvent {
        if ($jobOrder->status !== JobOrderStatus::ReadyForDispensing) {
            throw ValidationException::withMessages([
                'job_order' => ['Only ready-for-dispensing job orders can be dispensed.'],
            ]);
        }

        return DB::transaction(function () use ($jobOrder, $dispenser, $recipientName, $notes, $initialPaymentAmount, $initialPaymentMethod): DispensingEvent {
            // Check for existing billing record (prevent duplicates)
            $existing = BillingRecord::query()
                ->where('job_order_id', $jobOrder->id)
                ->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'job_order' => ['A billing record already exists for this job order.'],
                ]);
            }

            // Update job order status to dispensed
            $this->updateJobOrderStatus->handle($jobOrder, 'dispensed');

            // Create billing record
            $billingRecord = BillingRecord::query()->create([
                'patient_id' => $jobOrder->patient_id,
                'job_order_id' => $jobOrder->id,
                'encounter_id' => $jobOrder->encounter_id,
                'status' => BillingRecordStatus::Unpaid,
                'total_amount' => $jobOrder->total_amount,
                'amount_paid' => 0,
                'balance_due' => $jobOrder->total_amount,
                'recorded_by' => $dispenser->id,
                'recorded_at' => now(),
            ]);

            // Record dispensing event
            $event = DispensingEvent::query()->create([
                'job_order_id' => $jobOrder->id,
                'billing_record_id' => $billingRecord->id,
                'dispensed_by' => $dispenser->id,
                'recipient_name' => $recipientName,
                'notes' => $notes,
            ]);

            // Record initial payment if provided
            if ($initialPaymentAmount !== null && $initialPaymentAmount > 0) {
                app(RecordBillingPayment::class)->handle(
                    billingRecord: $billingRecord,
                    amount: $initialPaymentAmount,
                    paymentMethod: $initialPaymentMethod ?? 'cash',
                    recorder: $dispenser,
                );
            }

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $billingRecord,
                action: 'billing_record.created',
                metadata: [
                    'job_order_id' => $jobOrder->id,
                    'dispensing_event_id' => $event->id,
                    'total_amount' => $jobOrder->total_amount,
                    'initial_payment' => $initialPaymentAmount,
                ],
                actorId: $dispenser->id,
            );

            return $event;
        });
    }
}
