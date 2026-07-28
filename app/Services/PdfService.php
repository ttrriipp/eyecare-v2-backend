<?php

namespace App\Services;

use App\Models\Prescription;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class PdfService
{
    /**
     * Generate a compact portrait prescription PDF (4.25 × 6.5 inches).
     */
    public function prescriptionPrintout(Prescription $prescription): Response
    {
        $prescription->loadMissing(['patient', 'author']);

        $clinicHours = app(ClinicHoursFormatter::class)->formatWeeklyHours();

        $pdf = Pdf::loadView('pdf.prescription', [
            'prescription' => $prescription,
            'clinic' => config('clinic'),
            'clinicHours' => $clinicHours,
        ])->setPaper([0, 0, 306, 468], 'portrait'); // 4.25 × 6.5 inches in points

        $filename = 'prescription_'.$prescription->id.'.pdf';

        return $pdf->stream($filename);
    }
}
