<?php

namespace App\Http\Resources\Api;

use App\Actions\Ratings\FilterProfanity;
use App\Models\FrameRating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FrameRating
 */
class PublicProductReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'rating' => $this->rating,
            'comment' => app(FilterProfanity::class)->handle($this->comment),
            'created_at' => $this->created_at?->toISOString(),
            'attachment_url' => $this->attachmentUrl(),
        ];
    }

    private function attachmentUrl(): ?string
    {
        if (
            $this->public_attachment_consent_at === null
            || blank($this->attachment_path)
            || blank($this->attachment_public_id)
        ) {
            return null;
        }

        $product = $this->variant?->product;

        return match ($product?->product_type) {
            'frame' => route('api.v1.frames.reviews.attachments.show', [
                'frame' => $product->getKey(),
                'attachment' => $this->attachment_public_id,
            ], false),
            'accessory' => route('api.v1.accessories.reviews.attachments.show', [
                'accessory' => $product->getKey(),
                'attachment' => $this->attachment_public_id,
            ], false),
            default => null,
        };
    }
}
