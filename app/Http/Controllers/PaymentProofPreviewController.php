<?php

namespace App\Http\Controllers;

use App\Models\OrderPaymentProof;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentProofPreviewController extends Controller
{
    public function __invoke(Request $request, OrderPaymentProof $proof): StreamedResponse
    {
        $reviewer = $request->user();

        abort_unless(
            $reviewer?->canAccessPanel(Filament::getDefaultPanel())
                && ($reviewer->isAdmin() || $reviewer->isStaff()),
            403,
        );

        abort_unless(in_array($proof->mime_type, ['image/jpeg', 'image/png'], true), 404);

        $disk = Storage::disk((string) config('filesystems.payment_proof_disk', 'payment_proofs'));
        abort_unless($disk->exists($proof->file_path), 404);

        $filename = $proof->mime_type === 'image/png' ? 'payment-proof.png' : 'payment-proof.jpg';

        return $disk->response(
            $proof->file_path,
            $filename,
            [
                'Cache-Control' => 'no-store, private',
                'Content-Type' => $proof->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }
}
