<?php

namespace App\Http\Resources\Api;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class AccessoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'brand' => $this->brand?->name,
            'category' => $this->category?->name,
            'images' => $this->publicImages($this->images),
            'average_rating' => $this->averageRating(),
            'rating_count' => $this->ratingCount(),
            'variants' => AccessoryVariantResource::collection($this->whenLoaded('variants')),
        ];
    }

    private function averageRating(): ?float
    {
        if ($this->average_rating === null) {
            return null;
        }

        return round((float) $this->average_rating, 1);
    }

    private function ratingCount(): int
    {
        return (int) ($this->rating_count ?? 0);
    }

    /**
     * @param  array<int, mixed>|null  $images
     * @return list<string>
     */
    private function publicImages(?array $images): array
    {
        return collect($images ?? [])
            ->filter(fn (mixed $image): bool => is_string($image))
            ->map(fn (string $image): string => trim($image))
            ->filter(fn (string $image): bool => $this->isPublicImageReference($image))
            ->values()
            ->all();
    }

    private function isPublicImageReference(string $image): bool
    {
        if (
            $image === ''
            || str_contains($image, '\\')
            || str_contains($image, '..')
            || str_starts_with($image, '/')
            || filter_var($image, FILTER_VALIDATE_URL) !== false
        ) {
            return false;
        }

        $path = parse_url($image, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), [
            'avif',
            'bmp',
            'gif',
            'jpeg',
            'jpg',
            'png',
            'svg',
            'tif',
            'tiff',
            'webp',
        ], true);
    }
}
