<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
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
            'brand' => $this->brand->name,
            'category' => $this->category->name,
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'images' => $this->images ?? [],
        ];
    }
}
