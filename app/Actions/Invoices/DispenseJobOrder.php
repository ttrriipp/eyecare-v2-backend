<?php

namespace App\Actions\Invoices;

use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JobOrderStatus;
use App\Models\DispensingEvent;
use App\Models\Invoice;
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
     * Atomically dispense a job order and issue the invoice.
     */
    public function handle(
        JobOrder $jobOrder,
        User $dispenser,
        ?string $officialNumber = null,
        ?string $recipientName = null,
        ?string $notes = null,
    ): DispensingEvent {
        if ($jobOrder->status !== JobOrderStatus::ReadyForDispensing) {
            throw ValidationException::withMessages([
                'job_order' => ['Only ready-for-dispensing job orders can be dispensed.'],
            ]);
        }

        return DB::transaction(function () use ($jobOrder, $dispenser, $officialNumber, $recipientName, $notes): DispensingEvent {
            // Update job order status to dispensed
            $this->updateJobOrderStatus->handle($jobOrder, 'dispensed');

            // Find or create the invoice for this job order
            $invoice = Invoice::query()
                ->where('job_order_id', $jobOrder->id)
                ->where('status', '!=', InvoiceStatus::Voided)
                ->first();

            if ($invoice === null) {
                // Create a draft invoice from the job order
                $invoice = Invoice::query()->create([
                    'patient_id' => $jobOrder->patient_id,
                    'job_order_id' => $jobOrder->id,
                    'encounter_id' => $jobOrder->encounter_id,
                    'status' => InvoiceStatus::Draft,
                    'subtotal' => $jobOrder->total_amount,
                    'total' => $jobOrder->total_amount,
                    'balance_due' => $jobOrder->total_amount,
                ]);
            }

            // Issue the invoice with the official number
            $invoice->update([
                'official_number' => $officialNumber,
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
                'recorded_by' => $dispenser->id,
            ]);

            // Record the dispensing event
            return DispensingEvent::query()->create([
                'job_order_id' => $jobOrder->id,
                'invoice_id' => $invoice->id,
                'dispensed_by' => $dispenser->id,
                'recipient_name' => $recipientName,
                'notes' => $notes,
            ]);
        });
    }
}
