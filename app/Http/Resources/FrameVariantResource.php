<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductVariant
 */
class FrameVariantResource extends JsonResource
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
            'sku' => $this->sku,
            'price' => $this->price,
            'compare_at_price' => $this->compare_at_price,
            'attributes' => $this->attributes,
            'ar_eligible' => $this->ar_eligible,
            'ar_asset_reference' => $this->ar_asset_reference,
            'ar' => $this->relationLoaded('publishedArAsset')
                ? $this->publishedArAsset?->toPatientArray()
                : null,
            'images' => $this->images ?? [],
            'product' => $this->when(
                $this->relationLoaded('product') && $this->product !== null,
                fn () => [
                    'id' => $this->product->id,
                    'name' => $this->product->name,
                    'slug' => $this->product->slug,
                    'description' => $this->product->description,
                    'product_type' => $this->product->product_type,
                    'brand' => $this->product->relationLoaded('brand') ? $this->product->brand?->name : null,
                    'category' => $this->product->relationLoaded('category') ? $this->product->category?->name : null,
                ],
            ),
            // Deliberately excluded: cost_price, stock_quantity, low_stock_threshold
        ];
    }
}
