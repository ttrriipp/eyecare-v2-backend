<?php

namespace App\Http\Resources;

use App\Models\FrameReservationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FrameReservationItem
 */
class FrameReservationItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_variant_id' => $this->product_variant_id,
            'variant' => FrameReservationVariantResource::make($this->whenLoaded('variant')),
        ];
    }
}
