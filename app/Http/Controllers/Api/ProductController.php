<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $products = Product::query()
            ->where('is_active', true)
            ->with([
                'brand',
                'category',
                'variants' => fn ($query) => $query->where('is_active', true),
                'images',
            ])
            ->orderBy('name')
            ->get();

        return ProductResource::collection($products);
    }

    public function show(Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);

        $product->load([
            'brand',
            'category',
            'variants' => fn ($query) => $query->where('is_active', true),
            'images',
        ]);

        return response()->json([
            'data' => ProductResource::make($product),
        ]);
    }
}
