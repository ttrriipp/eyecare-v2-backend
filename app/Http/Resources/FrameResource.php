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
     */
    private function computeAverageRating(): ?float
    {
        if (! $this->relationLoaded('variants')) {
            return null;
        }

        $ratings = $this->variants
            ->flatMap(fn ($variant) => $variant->ratings ?? collect())
            ->where('is_hidden', false);

        if ($ratings->isEmpty()) {
            return null;
        }

        return round($ratings->avg('rating'), 1);
    }

    /**
     * Compute total rating count across all variants.
     */
    private function computeRatingCount(): int
    {
        if (! $this->relationLoaded('variants')) {
            return 0;
        }

        return $this->variants
            ->flatMap(fn ($variant) => $variant->ratings ?? collect())
            ->where('is_hidden', false)
            ->count();
    }
}
