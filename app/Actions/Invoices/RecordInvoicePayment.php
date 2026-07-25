<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordInvoicePayment
{
    /**
     * Record a payment against an invoice and recalculate balance.
     */
    public function handle(
        Invoice $invoice,
        float $amount,
        string $paymentMethod,
        User $recorder,
        ?string $referenceNumber = null,
        ?string $notes = null,
    ): InvoicePayment {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Payment amount must be greater than zero.'],
            ]);
        }

        if ($invoice->status->value === 'voided') {
            throw ValidationException::withMessages([
                'invoice' => ['Cannot record payments against a voided invoice.'],
            ]);
        }

        return DB::transaction(function () use ($invoice, $amount, $paymentMethod, $recorder, $referenceNumber, $notes): InvoicePayment {
            // Lock the invoice row to prevent concurrent overpayment
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->first();

            $payment = InvoicePayment::query()->create([
                'invoice_id' => $lockedInvoice->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,
                'recorded_by' => $recorder->id,
                'notes' => $notes,
                'status' => 'posted',
            ]);

            $lockedInvoice->recalculateBalance();

            return $payment;
        });
    }
}
