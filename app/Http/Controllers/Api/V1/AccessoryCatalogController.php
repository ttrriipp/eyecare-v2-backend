<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AccessoryResource;
use App\Models\FrameRating;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccessoryCatalogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'integer', 'exists:brands,id'],
            'category' => ['nullable', 'integer', 'exists:product_categories,id'],
            'sort' => ['nullable', 'string', 'in:name,newest,rating,most_rated'],
            'minimum_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rated' => ['nullable', 'string', 'in:all,rated,unrated'],
            'placement' => ['nullable', 'string', 'in:prescription'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $this->catalogQuery();

        $this->applyFilters($query, $validated);
        $this->applySort($query, $validated['sort'] ?? 'name');

        $products = $query->paginate((int) ($validated['per_page'] ?? 15));

        return AccessoryResource::collection($products);
    }

    public function show(Product $accessory): AccessoryResource
    {
        $catalogAccessory = $this->catalogQuery()
            ->whereKey($accessory->getKey())
            ->first();

        abort_if($catalogAccessory === null, 404);

        return AccessoryResource::make($catalogAccessory);
    }

    /**
     * @return Builder<Product>
     */
    private function catalogQuery(): Builder
    {
        $query = Product::query()
            ->active()
            ->where('product_type', 'accessory')
            ->whereHas('variants', function (Builder $variantQuery): void {
                $variantQuery
                    ->where('is_active', true)
                    ->whereHas('inventoryLots', function (Builder $lotQuery): void {
                        $lotQuery
                            ->where('quantity_on_hand', '>', 0)
                            ->where(function (Builder $expiryQuery): void {
                                $expiryQuery
                                    ->whereNull('expires_on')
                                    ->orWhereDate('expires_on', '>=', today());
                            });
                    });
            })
            ->with([
                'brand',
                'category',
                'variants' => fn ($variantQuery) => $variantQuery
                    ->where('is_active', true)
                    ->with('inventoryLots'),
            ]);

        $averageRating = FrameRating::query()
            ->selectRaw('AVG(frame_ratings.rating)')
            ->join('product_variants', 'product_variants.id', '=', 'frame_ratings.product_variant_id')
            ->whereColumn('product_variants.product_id', 'products.id')
            ->whereNull('frame_ratings.deleted_at')
            ->whereNull('product_variants.deleted_at');

        $ratingCount = FrameRating::query()
            ->selectRaw('COUNT(frame_ratings.id)')
            ->join('product_variants', 'product_variants.id', '=', 'frame_ratings.product_variant_id')
            ->whereColumn('product_variants.product_id', 'products.id')
            ->whereNull('frame_ratings.deleted_at')
            ->whereNull('product_variants.deleted_at');

        return $query
            ->select('products.*')
            ->selectSub($averageRating, 'average_rating')
            ->selectSub($ratingCount, 'rating_count');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyFilters(Builder $query, array $validated): void
    {
        if (filled($validated['search'] ?? null)) {
            $search = $validated['search'];

            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (filled($validated['brand'] ?? null)) {
            $query->where('brand_id', $validated['brand']);
        }

        if (filled($validated['category'] ?? null)) {
            $query->where('category_id', $validated['category']);
        }

        if (($validated['placement'] ?? null) === 'prescription') {
            $query->where('is_featured_for_prescription', true);
        }

        if (filled($validated['minimum_rating'] ?? null)) {
            $this->whereRatedProduct($query, (int) $validated['minimum_rating']);
        }

        if (($validated['rated'] ?? 'all') === 'rated') {
            $this->whereRatedProduct($query);
        } elseif (($validated['rated'] ?? 'all') === 'unrated') {
            $query->whereNotExists($this->ratingExistsQuery());
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'newest' => $query->orderByDesc('products.created_at')->orderBy('products.id'),
            'rating' => $query->orderByDesc('average_rating')
                ->orderByDesc('rating_count')
                ->orderBy('products.id'),
            'most_rated' => $query->orderByDesc('rating_count')
                ->orderByDesc('average_rating')
                ->orderBy('products.id'),
            default => $query->orderBy('products.name')->orderBy('products.id'),
        };
    }

    private function whereRatedProduct(Builder $query, ?int $minimumRating = null): void
    {
        $query->whereExists(function ($ratingQuery) use ($minimumRating): void {
            $ratingQuery
                ->selectRaw('1')
                ->from('frame_ratings')
                ->join('product_variants', 'product_variants.id', '=', 'frame_ratings.product_variant_id')
                ->whereColumn('product_variants.product_id', 'products.id')
                ->whereNull('frame_ratings.deleted_at')
                ->whereNull('product_variants.deleted_at')
                ->when(
                    $minimumRating !== null,
                    fn ($subQuery) => $subQuery
                        ->groupBy('product_variants.product_id')
                        ->havingRaw('AVG(frame_ratings.rating) >= ?', [$minimumRating]),
                );
        });
    }

    private function ratingExistsQuery(): \Closure
    {
        return function ($ratingQuery): void {
            $ratingQuery
                ->selectRaw('1')
                ->from('frame_ratings')
                ->join('product_variants', 'product_variants.id', '=', 'frame_ratings.product_variant_id')
                ->whereColumn('product_variants.product_id', 'products.id')
                ->whereNull('frame_ratings.deleted_at')
                ->whereNull('product_variants.deleted_at');
        };
    }
}
