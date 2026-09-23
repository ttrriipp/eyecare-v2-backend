<?php

namespace App\Actions\Ratings;

use App\Models\FrameRating;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamPublicProductReviewAttachment
{
    public function handle(FrameRating $rating): StreamedResponse
    {
        abort_unless(
            ! $rating->is_hidden
                && $rating->deleted_at === null
                && $rating->public_display_consent_at !== null
                && $rating->public_attachment_consent_at !== null
                && filled($rating->comment),
            404,
        );

        $mimeType = $rating->attachment_mime_type;
        abort_unless(in_array($mimeType, ['image/jpeg', 'image/png'], true), 404);

        $disk = Storage::disk((string) config(
            'filesystems.product_review_attachments_disk',
            'product_review_attachments',
        ));
        $path = $rating->attachment_path;
        abort_unless(is_string($path) && $path !== '' && $disk->exists($path), 404);

        $extension = $mimeType === 'image/png' ? 'png' : 'jpg';

        return $disk->response(
            $path,
            "product-review-image.{$extension}",
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
