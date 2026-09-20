<?php

namespace App\Http\Resources\Api;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductVariant
 */
class AccessoryVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => number_format((float) $this->price, 2, '.', ''),
            'compare_at_price' => $this->compare_at_price !== null
                ? number_format((float) $this->compare_at_price, 2, '.', '')
                : null,
            'attributes' => $this->attributes ?? [],
            'images' => $this->publicImages($this->images),
            'availability' => $this->availability(),
        ];
    }

    private function availability(): string
    {
        $stock = $this->usableStockQuantity();

        if ($stock !== null && $stock <= 0) {
            return 'unavailable';
        }

        if ($stock !== null && $this->low_stock_threshold > 0 && $stock <= $this->low_stock_threshold) {
            return 'low_stock';
        }

        return 'available';
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
