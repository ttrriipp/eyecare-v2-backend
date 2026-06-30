<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->where('is_active', true)
            ->where('product_type', 'frame')
            ->with([
                'brand',
                'category',
                'variants' => fn ($q) => $q->where('is_active', true),
            ]);

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
                fn ($v) => $v->where('price', '>=', $request->input('min_price'))
            )
        );

        // Filter by max variant price
        $query->when(
            $request->filled('max_price'),
            fn ($q) => $q->whereHas(
                'variants',
                fn ($v) => $v->where('price', '<=', $request->input('max_price'))
            )
        );

        // Filter by stock availability
        $query->when(
            $request->boolean('in_stock'),
            fn ($q) => $q->whereHas(
                'variants',
                fn ($v) => $v->where('stock_quantity', '>', 0)
            )
        );

        // Sorting
        $sort = $request->input('sort', 'name');

        match ($sort) {
            'price_asc' => $query->orderByRaw('(SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id) ASC'),
            'price_desc' => $query->orderByRaw('(SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id) DESC'),
            'newest' => $query->latest(),
            default => $query->orderBy('name'),
        };

        return ProductResource::collection(
            $query->paginate($request->integer('per_page', 15))
        );
    }

    public function show(Product $product): JsonResponse
    {
        abort_unless($product->is_active && $product->product_type === 'frame', 404);

        $product->load([
            'brand',
            'category',
            'variants' => fn ($query) => $query->where('is_active', true),
        ]);

        return response()->json([
            'data' => ProductResource::make($product),
        ]);
    }
}
