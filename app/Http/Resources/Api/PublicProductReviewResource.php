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
        ];
    }
}
