<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FrameRating;
use App\Models\JobOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FrameRatingAttachmentController extends Controller
{
    public function show(Request $request, JobOrderItem $item): StreamedResponse
    {
        $patient = $request->user()?->patient;
        abort_unless($patient !== null, 404);

        $jobOrder = $item->jobOrder;
        abort_unless($jobOrder !== null && $jobOrder->patient_id === $patient->id, 404);
        abort_unless($item->product_variant_id !== null, 404);

        $rating = FrameRating::query()
            ->where('patient_id', $patient->id)
            ->where('product_variant_id', $item->product_variant_id)
            ->first();

        abort_unless($rating !== null, 404);

        $mimeType = $rating->attachment_mime_type;
        abort_unless(in_array($mimeType, ['image/jpeg', 'image/png'], true), 404);

        $path = $rating->attachment_path;
        abort_unless(is_string($path) && $path !== '', 404);

        $disk = Storage::disk((string) config(
            'filesystems.product_review_attachments_disk',
            'product_review_attachments',
        ));
        abort_unless($disk->exists($path), 404);

        $extension = $mimeType === 'image/png' ? 'png' : 'jpg';

        return $disk->response(
            $path,
            "product-rating-image.{$extension}",
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
