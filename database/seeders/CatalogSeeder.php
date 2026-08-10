<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['name' => 'Single Vision', 'description' => 'Standard single vision lenses.', 'price' => 2500.00],
            ['name' => 'Progressive', 'description' => 'Progressive multifocal lenses.', 'price' => 6500.00],
            ['name' => 'Bifocal', 'description' => 'Bifocal lenses with visible segment.', 'price' => 4500.00],
            [
                'name' => 'Essilor Varilux Progressive 1.67',
                'description' => 'Premium externally prepared progressive lens package with 1.67 refractive index.',
                'price' => 6500.00,
            ],
            [
                'name' => 'Zeiss Single Vision 1.50',
                'description' => 'High-clarity externally prepared single vision lens package with 1.50 refractive index.',
                'price' => 2800.00,
            ],
        ])->each(fn (array $attributes) => LensCategory::query()->firstOrCreate(
            ['name' => $attributes['name']],
            ['description' => $attributes['description'], 'price' => $attributes['price']],
        ));

        LensOption::query()->firstOrCreate(
            ['name' => 'Anti-Reflective'],
            [
                'description' => 'Reduces glare and improves visual clarity on prescription lenses.',
                'price' => 1000.00,
                'is_active' => true,
            ],
        );

        $brand = Brand::query()->firstOrCreate(['name' => 'VisionCraft']);
        $category = ProductCategory::query()->firstOrCreate(['name' => 'Frames']);
        $contactLensCategory = ProductCategory::query()->firstOrCreate(['name' => 'Contact Lenses']);
        $accessoryCategory = ProductCategory::query()->firstOrCreate(['name' => 'Accessories']);

        // Contact-lens products
        $contactLensProduct = Product::query()->firstOrCreate(
            ['slug' => 'acuvue-oasys'],
            [
                'brand_id' => $brand->id,
                'category_id' => $contactLensCategory->id,
                'name' => 'Acuvue Oasys',
                'description' => 'Reusable contact lenses with clear and toric prescription variants.',
                'is_active' => true,
                'product_type' => 'contact_lens',
                'images' => [],
            ],
        );

        foreach ([
            [
                'name' => 'Clear -2.00 / 6-pack',
                'sku' => 'ACOASYS-200-6PK',
                'price' => 1299.00,
                'attributes' => [
                    'power' => '-2.00',
                    'base_curve' => 8.4,
                    'diameter' => 14.0,
                    'cylinder' => null,
                    'axis' => null,
                    'add' => null,
                    'color' => null,
                    'pack_size' => 6,
                ],
                'stock_quantity' => 18,
                'low_stock_threshold' => 4,
                'target_stock_level' => 24,
            ],
            [
                'name' => 'Toric -3.00 / -1.25 x 180 / 6-pack',
                'sku' => 'ACOASYS-TORIC-300-125-180',
                'price' => 1699.00,
                'attributes' => [
                    'power' => '-3.00',
                    'base_curve' => 8.7,
                    'diameter' => 14.5,
                    'cylinder' => '-1.25',
                    'axis' => 180,
                    'add' => null,
                    'color' => null,
                    'pack_size' => 6,
                ],
                'stock_quantity' => 12,
                'low_stock_threshold' => 3,
                'target_stock_level' => 18,
            ],
        ] as $variantData) {
            ProductVariant::query()->firstOrCreate(
                ['sku' => $variantData['sku']],
                [
                    'product_id' => $contactLensProduct->id,
                    'name' => $variantData['name'],
                    'is_active' => true,
                    'price' => $variantData['price'],
                    'attributes' => $variantData['attributes'],
                    'stock_quantity' => $variantData['stock_quantity'],
                    'low_stock_threshold' => $variantData['low_stock_threshold'],
                    'target_stock_level' => $variantData['target_stock_level'],
                    'ar_eligible' => false,
                    'ar_asset_reference' => null,
                    'images' => [],
                ],
            );
        }

        // Accessory products
        $accessoryProducts = [
            [
                'name' => 'Lens Cleaning Kit',
                'slug' => 'lens-cleaning-kit',
                'description' => 'Complete cleaning kit with lens-safe solution and microfiber cloth.',
                'variants' => [
                    [
                        'name' => 'Standard',
                        'sku' => 'LCK-STD-001',
                        'price' => 299.00,
                        'stock_quantity' => 24,
                        'low_stock_threshold' => 5,
                        'target_stock_level' => 30,
                    ],
                    [
                        'name' => 'Travel Size',
                        'sku' => 'LCK-TRV-001',
                        'price' => 179.00,
                        'stock_quantity' => 18,
                        'low_stock_threshold' => 4,
                        'target_stock_level' => 24,
                    ],
                ],
            ],
            [
                'name' => 'Hard Shell Glasses Case',
                'slug' => 'hard-shell-glasses-case',
                'description' => 'Protective hard shell case suitable for most eyeglasses and sunglasses.',
                'variants' => [
                    [
                        'name' => 'Standard',
                        'sku' => 'HSC-STD-001',
                        'price' => 249.00,
                        'stock_quantity' => 15,
                        'low_stock_threshold' => 3,
                        'target_stock_level' => 20,
                    ],
                ],
            ],
            [
                'name' => 'Microfiber Cleaning Cloth',
                'slug' => 'microfiber-cleaning-cloth',
                'description' => 'Reusable lint-free microfiber cloth for everyday lens cleaning.',
                'variants' => [
                    [
                        'name' => 'Standard',
                        'sku' => 'MCC-STD-001',
                        'price' => 99.00,
                        'stock_quantity' => 40,
                        'low_stock_threshold' => 10,
                        'target_stock_level' => 50,
                    ],
                ],
            ],
        ];

        foreach ($accessoryProducts as $productData) {
            $product = Product::query()->firstOrCreate(
                ['slug' => $productData['slug']],
                [
                    'brand_id' => $brand->id,
                    'category_id' => $accessoryCategory->id,
                    'name' => $productData['name'],
                    'description' => $productData['description'],
                    'is_active' => true,
                    'product_type' => 'accessory',
                    'images' => [],
                ],
            );

            foreach ($productData['variants'] as $variantData) {
                ProductVariant::query()->firstOrCreate(
                    ['sku' => $variantData['sku']],
                    [
                        'product_id' => $product->id,
                        'name' => $variantData['name'],
                        'is_active' => true,
                        'price' => $variantData['price'],
                        'stock_quantity' => $variantData['stock_quantity'],
                        'low_stock_threshold' => $variantData['low_stock_threshold'],
                        'target_stock_level' => $variantData['target_stock_level'],
                        'ar_eligible' => false,
                        'ar_asset_reference' => null,
                        'images' => [],
                    ],
                );
            }
        }

        // Frame products
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
                        'attributes' => ['lens_width' => 52, 'bridge' => 18, 'temple' => 140],
                        'stock_quantity' => 10,
                        'low_stock_threshold' => 2,
                        'ar_eligible' => true,
                        'ar_asset_reference' => 'frames/classic-rectangle-matte-black.glb',
                    ],
                    [
                        'name' => 'Tortoise',
                        'sku' => 'CRF-TRT-001',
                        'price' => 169.99,
                        'attributes' => ['lens_width' => 52, 'bridge' => 18, 'temple' => 140],
                        'stock_quantity' => 8,
                        'low_stock_threshold' => 2,
                        'ar_eligible' => true,
                        'ar_asset_reference' => 'frames/classic-rectangle-tortoise.glb',
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
                        'attributes' => ['lens_width' => 48, 'bridge' => 20, 'temple' => 145],
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
                    'product_type' => 'frame',
                ],
            );

            foreach ($productData['variants'] as $variantData) {
                ProductVariant::query()->firstOrCreate(
                    ['sku' => $variantData['sku']],
                    [
                        'product_id' => $product->id,
                        'name' => $variantData['name'],
                        'is_active' => true,
                        'price' => $variantData['price'],
                        'attributes' => $variantData['attributes'] ?? null,
                        'stock_quantity' => $variantData['stock_quantity'],
                        'low_stock_threshold' => $variantData['low_stock_threshold'],
                        'ar_eligible' => $variantData['ar_eligible'],
                        'ar_asset_reference' => $variantData['ar_asset_reference'],
                    ],
                );
            }
        }
    }
}
