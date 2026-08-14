<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FrameResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FrameController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->active()
            ->where('product_type', 'frame')
            ->where(function ($q): void {
                $q->whereHas('variants', fn ($sub) => $sub
                    ->where('is_active', true)
                    ->where('ar_eligible', true)
                    ->whereNotNull('ar_asset_reference'))
                    ->orWhereDoesntHave('variants', fn ($sub) => $sub
                        ->where('ar_eligible', true));
            })
            ->with(['brand', 'category', 'variants' => fn ($q) => $q
                ->where('is_active', true)
                ->with(['ratings'])]);

        $query->when(
            $request->filled('search'),
            fn ($q) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$request->input('search')}%")
                ->orWhere('description', 'like', "%{$request->input('search')}%"))
        );

        $query->when(
            $request->filled('brand'),
            fn ($q) => $q->where('brand_id', $request->integer('brand'))
        );

        $query->when(
            $request->filled('category'),
            fn ($q) => $q->where('category_id', $request->integer('category'))
        );

        $sort = $request->input('sort', 'name');
        match ($sort) {
            'newest' => $query->latest(),
            default => $query->orderBy('name'),
        };

        return FrameResource::collection(
            $query->paginate($request->integer('per_page', 15))
        );
    }

    public function show(Product $frame): JsonResponse
    {
        $catalogFrame = Product::query()
            ->active()
            ->where('product_type', 'frame')
            ->where('id', $frame->id)
            ->where(function ($q): void {
                $q->whereHas('variants', fn ($sub) => $sub
                    ->where('is_active', true)
                    ->where('ar_eligible', true)
                    ->whereNotNull('ar_asset_reference'))
                    ->orWhereDoesntHave('variants', fn ($sub) => $sub
                        ->where('ar_eligible', true));
            })
            ->with(['brand', 'category', 'variants' => fn ($q) => $q
                ->where('is_active', true)
                ->with(['ratings'])])
            ->first();

        abort_if($catalogFrame === null, 404);

        return response()->json([
            'data' => FrameResource::make($catalogFrame),
        ]);
    }
}
