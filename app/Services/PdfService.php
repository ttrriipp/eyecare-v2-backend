<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Prescription;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class PdfService
{
    /**
     * Generate a billing receipt PDF response.
     */
    public function billingReceipt(Billing $billing): Response
    {
        $billing->loadMissing(['customer', 'items', 'payments.paymentMethod', 'payments.status', 'discountType']);

        $pdf = Pdf::loadView('pdf.billing-receipt', ['billing' => $billing]);

        $filename = strtolower(str_replace('-', '_', $billing->billing_number ?? 'receipt')).'.pdf';

        return $pdf->stream($filename);
    }

    /**
     * Generate a prescription printout PDF response (A4 portrait).
     */
    public function prescriptionPrintout(Prescription $prescription): Response
    {
        $prescription->loadMissing(['customer', 'createdBy']);

        $pdf = Pdf::loadView('pdf.prescription', ['prescription' => $prescription]);

        $filename = 'prescription_'.$prescription->id.'.pdf';

        return $pdf->stream($filename);
    }

    /**
     * Generate a wallet/credit-card size prescription card (85.6mm × 54mm landscape).
     */
    public function prescriptionCard(Prescription $prescription): Response
    {
        $prescription->loadMissing(['customer', 'createdBy']);

        $pdf = Pdf::loadView('pdf.prescription-card', ['prescription' => $prescription])
            ->setPaper([0, 0, 242.24, 153.07], 'landscape'); // 85.6mm × 54mm in points

        $filename = 'prescription_card_'.$prescription->id.'.pdf';

        return $pdf->stream($filename);
    }
}
