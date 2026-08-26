<?php

namespace App\Http\Resources;

use App\Models\SavedFrame;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavedFrame
 */
class SavedFrameResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_variant_id' => $this->product_variant_id,
            'saved_at' => $this->created_at->toIso8601String(),
            'availability' => $this->computeAvailability(),
            'variant' => FrameVariantResource::make($this->whenLoaded('variant')),
        ];
    }

    private function computeAvailability(): string
    {
        $variant = $this->whenLoaded('variant');

        if ($variant === null) {
            return 'unavailable';
        }

        if ($variant->trashed() || ! $variant->is_active) {
            return 'unavailable';
        }

        $product = $variant->relationLoaded('product') ? $variant->product : $variant->product;

        if ($product === null || $product->trashed() || ! $product->is_active) {
            return 'unavailable';
        }

        return $variant->stock_quantity > 0 ? 'available' : 'unavailable';
    }
}
