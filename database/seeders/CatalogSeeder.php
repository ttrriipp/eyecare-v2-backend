<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\LensType;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect([
            ['name' => 'single_vision', 'description' => 'Standard single vision lenses.'],
            ['name' => 'progressive', 'description' => 'Progressive multifocal lenses.'],
            ['name' => 'bifocal', 'description' => 'Bifocal lenses with visible segment.'],
        ])->each(fn (array $attributes) => LensType::query()->firstOrCreate(
            ['name' => $attributes['name']],
            ['description' => $attributes['description']],
        ));

        $brand = Brand::query()->firstOrCreate(['name' => 'VisionCraft']);
        $category = Category::query()->firstOrCreate(['name' => 'frames']);

        $products = [
            [
                'name' => 'Classic Rectangle Frame',
                'slug' => 'classic-rectangle-frame',
                'description' => 'Demo acetate frame for defense walkthrough.',
                'variants' => [
                    [
                        'name' => 'Matte Black',
                        'sku' => 'CRF-BLK-001',
                        'price' => 159.99,
                        'dimensions' => ['lens_width' => 52, 'bridge' => 18, 'temple' => 140],
                        'stock_quantity' => 10,
                        'low_stock_threshold' => 2,
                        'ar_eligible' => true,
                        'ar_asset_reference' => 'frames/classic-rectangle-matte-black.glb',
                    ],
                ],
            ],
            [
                'name' => 'Round Metal Frame',
                'slug' => 'round-metal-frame',
                'description' => 'Lightweight metal frame for demo catalog browsing.',
                'variants' => [
                    [
                        'name' => 'Gold',
                        'sku' => 'RMF-GLD-001',
                        'price' => 139.99,
                        'dimensions' => ['lens_width' => 48, 'bridge' => 20, 'temple' => 145],
                        'stock_quantity' => 6,
                        'low_stock_threshold' => 2,
                        'ar_eligible' => true,
                        'ar_asset_reference' => 'frames/round-metal-gold.glb',
                    ],
                ],
            ],
        ];

        foreach ($products as $productData) {
            $product = Product::query()->firstOrCreate(
                ['slug' => $productData['slug']],
                [
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'name' => $productData['name'],
                    'description' => $productData['description'],
                    'is_active' => true,
                ],
            );

            foreach ($productData['variants'] as $variantData) {
                $variant = ProductVariant::query()->firstOrCreate(
                    ['sku' => $variantData['sku']],
                    [
                        'product_id' => $product->id,
                        'name' => $variantData['name'],
                        'is_active' => true,
                        'price' => $variantData['price'],
                        'dimensions' => $variantData['dimensions'] ?? null,
                        'stock_quantity' => $variantData['stock_quantity'],
                        'low_stock_threshold' => $variantData['low_stock_threshold'],
                        'ar_eligible' => $variantData['ar_eligible'],
                        'ar_asset_reference' => $variantData['ar_asset_reference'],
                    ],
                );

                ProductImage::query()->firstOrCreate(
                    [
                        'product_id' => $product->id,
                        'product_variant_id' => $variant->id,
                        'path' => 'products/'.Str::slug($product->slug).'-'.Str::slug($variant->name).'.jpg',
                    ],
                    [
                        'sort_order' => 0,
                        'is_primary' => true,
                    ],
                );
            }
        }
    }
}
