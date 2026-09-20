<?php

namespace App\Http\Controllers;

use App\Models\OrderPaymentProof;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentProofDownloadController extends Controller
{
    public function __invoke(Request $request, OrderPaymentProof $proof): StreamedResponse
    {
        $reviewer = $request->user();

        abort_unless(
            $reviewer?->canAccessPanel(Filament::getDefaultPanel())
                && ($reviewer->isAdmin() || $reviewer->isStaff()),
            403,
        );

        $disk = Storage::disk((string) config('filesystems.payment_proof_disk', 'payment_proofs'));
        abort_unless($disk->exists($proof->file_path), 404);
        $downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($proof->original_name)) ?: 'payment-proof';

        return $disk->download(
            $proof->file_path,
            $downloadName,
            [
                'Content-Type' => $proof->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
