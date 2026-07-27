<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sanitized variant for patient-facing reservation responses.
 * Excludes internal commercial/inventory fields.
 *
 * @mixin ProductVariant
 */
class FrameReservationVariantResource extends JsonResource
{
    /**
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
            'images' => $this->images ?? [],
            'product' => FrameReservationProductResource::make($this->whenLoaded('product')),
        ];
    }
}
