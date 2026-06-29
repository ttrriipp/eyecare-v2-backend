<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductVariant
 */
class ProductVariantResource extends JsonResource
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
            'ar_asset_reference' => $this->ar_eligible ? $this->ar_asset_reference : null,
            'in_stock' => $this->stock_quantity > 0,
            'images' => $this->images ?? [],
        ];
    }
}
