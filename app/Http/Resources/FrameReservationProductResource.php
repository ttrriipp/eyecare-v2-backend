<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sanitized product for patient-facing reservation responses.
 *
 * @mixin Product
 */
class FrameReservationProductResource extends JsonResource
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
            'product_type' => $this->product_type,
            'brand' => $this->whenLoaded('brand', fn () => $this->brand->name),
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
        ];
    }
}
