<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class FrameResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'product_type' => $this->product_type,
            'brand' => $this->brand?->name,
            'category' => $this->category?->name,
            'variants' => FrameVariantResource::collection($this->whenLoaded('variants')),
            'images' => $this->images ?? [],
            'average_rating' => $this->computeAverageRating(),
            'rating_count' => $this->computeRatingCount(),
        ];
    }

    /**
     * Compute average rating across all variants.
     *
     * Includes hidden ratings — hiding suppresses the comment only,
     * not the star. This was a bug in the original implementation
     * where hidden ratings were excluded from the aggregate entirely.
     */
    private function computeAverageRating(): ?float
    {
        if (! $this->relationLoaded('variants')) {
            return null;
        }

        $ratings = $this->variants
            ->flatMap(fn ($variant) => $variant->ratings ?? collect());

        if ($ratings->isEmpty()) {
            return null;
        }

        return round($ratings->avg('rating'), 1);
    }

    /**
     * Compute total rating count across all variants.
     *
     * Includes hidden ratings — same rationale as computeAverageRating.
     */
    private function computeRatingCount(): int
    {
        if (! $this->relationLoaded('variants')) {
            return 0;
        }

        return $this->variants
            ->flatMap(fn ($variant) => $variant->ratings ?? collect())
            ->count();
    }
}
