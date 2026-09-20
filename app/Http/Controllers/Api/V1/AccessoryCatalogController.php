<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessoryCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'integer', 'exists:brands,id'],
            'category' => ['nullable', 'integer', 'exists:product_categories,id'],
            'sort' => ['nullable', 'string', 'in:name,newest,rating,most_rated'],
            'minimum_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rated' => ['nullable', 'string', 'in:all,rated,unrated'],
            'placement' => ['nullable', 'string', 'in:prescription'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Product::query()
            ->active()
            ->where('product_type', 'accessory')
            ->whereHas('variants', fn ($q) => $q->active()->where('stock_quantity', '>', 0));

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (! empty($validated['brand'])) {
            $query->where('brand_id', $validated['brand']);
        }

        if (! empty($validated['category'])) {
            $query->where('category_id', $validated['category']);
        }

        if (($validated['placement'] ?? null) === 'prescription') {
            $query->where('is_featured_for_prescription', true);
        }

        $sort = $validated['sort'] ?? 'name';

        if ($sort === 'newest') {
            $query->orderByDesc('created_at');
        } elseif ($sort === 'rating') {
            $query->orderByDesc('average_rating')->orderByDesc('rating_count');
        } elseif ($sort === 'most_rated') {
            $query->orderByDesc('rating_count')->orderByDesc('average_rating');
        } else {
            $query->orderBy('name');
        }

        $perPage = min((int) ($validated['per_page'] ?? 15), 50);
        $products = $query->paginate($perPage);

        return response()->json([
            'data' => $products->items(),
            'links' => [
                'first' => $products->url(1),
                'last' => $products->url($products->lastPage()),
                'prev' => $products->previousPageUrl(),
                'next' => $products->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function show(Product $accessory): JsonResponse
    {
        if ($accessory->product_type !== 'accessory' || ! $accessory->is_active) {
            abort(404);
        }

        $variants = $accessory->variants()
            ->active()
            ->get()
            ->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'name' => $variant->name,
                'price' => number_format((float) $variant->price, 2, '.', ''),
                'attributes' => $variant->attributes,
                'images' => $variant->images,
                'availability' => $this->resolveAvailability($variant),
            ]);

        return response()->json([
            'data' => [
                'id' => $accessory->id,
                'name' => $accessory->name,
                'description' => $accessory->description,
                'brand' => $accessory->brand?->name,
                'category' => $accessory->category?->name,
                'images' => $accessory->images,
                'average_rating' => $accessory->average_rating,
                'rating_count' => $accessory->rating_count ?? 0,
                'variants' => $variants,
            ],
        ]);
    }

    private function resolveAvailability(ProductVariant $variant): string
    {
        $stock = $variant->usableStockQuantity();

        if ($stock <= 0) {
            return 'unavailable';
        }

        if ($variant->low_stock_threshold > 0 && $stock <= $variant->low_stock_threshold) {
            return 'low_stock';
        }

        return 'available';
    }
}
