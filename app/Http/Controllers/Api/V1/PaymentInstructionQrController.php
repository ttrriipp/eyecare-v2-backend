<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobOrderStatus;
use App\Enums\OrderPaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Services\Payments\PaymentInstructionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentInstructionQrController extends Controller
{
    public function show(
        Request $request,
        JobOrder $jobOrder,
        string $method,
        PaymentInstructionCatalog $catalog,
    ): StreamedResponse {
        abort_unless($jobOrder->patient_id === $request->user()->patient?->id, 404);
        abort_unless($jobOrder->status === JobOrderStatus::PendingPayment, 404);
        abort_unless($jobOrder->payment_expires_at?->isFuture() === true, 404);

        $paymentMethod = OrderPaymentMethod::tryFrom($method);
        abort_if($paymentMethod === null, 404);

        $instruction = collect($catalog->forOrder($jobOrder))
            ->first(fn (array $item): bool => ($item['method'] ?? null) === $paymentMethod->value);
        $path = $instruction['qr_image_path'] ?? null;

        abort_unless(is_string($path) && $path !== '', 404);
        abort_unless($this->isSafePath($path), 404);

        $disk = Storage::disk((string) config('filesystems.payment_instructions_disk', 'payment_instructions'));
        abort_unless($disk->exists($path), 404);
        $contentType = $this->contentType($path);
        abort_unless($contentType !== null, 404);

        return $disk->response(
            $path,
            basename($path),
            [
                'Content-Type' => $contentType,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function isSafePath(string $path): bool
    {
        return ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && ! str_starts_with($path, '/');
    }

    private function contentType(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };
    }
}
