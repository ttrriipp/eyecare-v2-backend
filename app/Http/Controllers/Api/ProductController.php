<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->mobileCatalogQuery();

        // Search by name or description
        $query->when(
            $request->filled('search'),
            fn ($q) => $q->where(function ($q) use ($request) {
                $term = '%'.$request->input('search').'%';
                $q->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term);
            })
        );

        // Filter by brand
        $query->when(
            $request->filled('brand'),
            fn ($q) => $q->where('brand_id', $request->integer('brand'))
        );

        // Filter by category
        $query->when(
            $request->filled('category'),
            fn ($q) => $q->where('category_id', $request->integer('category'))
        );

        // Filter by min variant price
        $query->when(
            $request->filled('min_price'),
            fn ($q) => $q->whereHas(
                'variants',
                fn (Builder $variantQuery): Builder => $variantQuery
                    ->visibleInMobileCatalog()
                    ->where('price', '>=', $request->input('min_price'))
            )
        );

        // Filter by max variant price
        $query->when(
            $request->filled('max_price'),
            fn ($q) => $q->whereHas(
                'variants',
                fn (Builder $variantQuery): Builder => $variantQuery
                    ->visibleInMobileCatalog()
                    ->where('price', '<=', $request->input('max_price'))
            )
        );

        // Filter by stock availability
        $query->when(
            $request->boolean('in_stock'),
            fn ($q) => $q->whereHas(
                'variants',
                fn (Builder $variantQuery): Builder => $variantQuery
                    ->visibleInMobileCatalog()
                    ->where('stock_quantity', '>', 0)
            )
        );

        // Sorting
        $sort = $request->input('sort', 'name');

        if (in_array($sort, ['price_asc', 'price_desc'], true)) {
            $query->withMin(
                [
                    'variants as mobile_catalog_min_price' => fn (Builder $variantQuery): Builder => $variantQuery
                        ->visibleInMobileCatalog(),
                ],
                'price',
            );
        }

        match ($sort) {
            'price_asc' => $query->orderBy('mobile_catalog_min_price'),
            'price_desc' => $query->orderByDesc('mobile_catalog_min_price'),
            'newest' => $query->latest(),
            default => $query->orderBy('name'),
        };

        return ProductResource::collection(
            $query->paginate($request->integer('per_page', 15))
        );
    }

    public function show(Product $product): JsonResponse
    {
        $catalogProduct = $this->mobileCatalogQuery()
            ->whereKey($product->getKey())
            ->firstOrFail();

        return response()->json([
            'data' => ProductResource::make($catalogProduct),
        ]);
    }

    /**
     * @return Builder<Product>
     */
    private function mobileCatalogQuery(): Builder
    {
        return Product::query()
            ->visibleInMobileCatalog()
            ->with([
                'brand',
                'category',
                'variants' => fn (HasMany $variantQuery): HasMany => $variantQuery->visibleInMobileCatalog(),
            ]);
    }
}
