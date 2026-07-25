<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectInvoicePayment
{
    /**
     * Void a payment and create a replacement with the corrected amount.
     *
     * The original payment is preserved for audit — only its effective state changes.
     */
    public function handle(
        InvoicePayment $originalPayment,
        float $correctedAmount,
        User $corrector,
        string $reason,
    ): InvoicePayment {
        if ($correctedAmount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Corrected amount must be greater than zero.'],
            ]);
        }

        $invoice = $originalPayment->invoice;

        if ($invoice->status->value === 'voided') {
            throw ValidationException::withMessages([
                'invoice' => ['Cannot correct payments on a voided invoice.'],
            ]);
        }

        return DB::transaction(function () use ($originalPayment, $correctedAmount, $corrector, $reason, $invoice): InvoicePayment {
            // Lock the invoice row
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->first();

            // Void the original payment (preserve for audit)
            $originalPayment->update([
                'status' => 'voided',
                'notes' => trim(($originalPayment->notes ? $originalPayment->notes."\n" : '')."VOIDED: {$reason}"),
            ]);

            // Create replacement payment
            $replacement = InvoicePayment::query()->create([
                'invoice_id' => $lockedInvoice->id,
                'amount' => $correctedAmount,
                'payment_method' => $originalPayment->payment_method,
                'reference_number' => $originalPayment->reference_number,
                'recorded_by' => $corrector->id,
                'notes' => "Correction of payment #{$originalPayment->id}: {$reason}",
            ]);

            $lockedInvoice->recalculateBalance();

            return $replacement;
        });
    }
}
