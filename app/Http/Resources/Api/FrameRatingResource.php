<?php

namespace App\Http\Resources\Api;

use App\Models\FrameRating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FrameRating
 */
class FrameRatingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAuthor = $request->user()?->patient_id === $this->patient_id;

        return [
            'id' => $this->id,
            'item_id' => $this->dispensing_event_id,
            'product_variant_id' => $this->product_variant_id,
            'rating' => $this->rating,
            'comment' => $this->shouldShowComment($isAuthor) ? $this->comment : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function shouldShowComment(bool $isAuthor): bool
    {
        if (! $this->is_hidden) {
            return true;
        }

        // Author can see their own hidden comment
        return $isAuthor;
    }
}
