<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Seeds the clinic's current physical catalog.
 *
 * Prices and stock quantities are provisional local-development values where
 * the workbook did not provide verified figures. Contact-lens lots remain
 * empty until receiving data is available.
 */
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

        LensCategory::query()->firstOrCreate(
            ['name' => 'Photochromic (Discontinued)'],
            [
                'description' => 'Legacy light-adaptive lens package, no longer offered to new patients.',
                'price' => 5500.00,
                'is_active' => false,
            ],
        );

        LensOption::query()->firstOrCreate(
            ['name' => 'Anti-Reflective'],
            [
                'description' => 'Reduces glare and improves visual clarity on prescription lenses.',
                'price' => 1000.00,
                'is_active' => true,
            ],
        );

        LensOption::query()->firstOrCreate(
            ['name' => 'Blue Light Filter (Discontinued)'],
            [
                'description' => 'Legacy coating superseded by the current anti-reflective package.',
                'price' => 800.00,
                'is_active' => false,
            ],
        );

        collect([
            ['name' => 'Comprehensive Eye Exam', 'description' => 'Full refraction and ocular health assessment.', 'price' => 800.00],
            ['name' => 'Contact Lens Fitting', 'description' => 'Fitting and trial session for contact lens wearers.', 'price' => 1200.00],
            ['name' => 'Frame Adjustment', 'description' => 'On-the-spot frame adjustment and realignment.', 'price' => 0.00],
        ])->each(fn (array $attributes) => Service::query()->firstOrCreate(
            ['name' => $attributes['name']],
            ['description' => $attributes['description'], 'price' => $attributes['price']],
        ));

        Service::query()->firstOrCreate(
            ['name' => 'Orthokeratology Consultation (Retired)'],
            [
                'description' => 'Retired service, kept for historical billing records.',
                'price' => 2000.00,
                'is_active' => false,
            ],
        );

        $this->retireLegacyCatalog();

        $brands = [];
        foreach ([
            'SOFIA EYEWEAR',
            'Mormaii',
            'ANTHOS',
            "C'est Joli",
            'Unknown',
            'New Look',
            'Systane / Alcon',
            'EnSight / Cipla Health',
            'Alcon',
        ] as $brandName) {
            $brands[$brandName] = Brand::query()->updateOrCreate(
                ['name' => $brandName],
                ['is_active' => true],
            );
        }

        $categories = [];
        foreach ([
            'Optical Frame',
            'Rimless Optical Frame',
            'Sports Optical Frame',
            'Sports Sunglasses',
            'Contact Lens Care',
            'Eye Drops',
            'Colored Contact Lens',
        ] as $categoryName) {
            $categories[$categoryName] = ProductCategory::query()->updateOrCreate(
                ['name' => $categoryName],
                ['is_active' => true],
            );
        }

        foreach ([
            [
                'brand' => 'SOFIA EYEWEAR',
                'category' => 'Optical Frame',
                'name' => 'SOFIA 2860',
                'slug' => 'sofia-2860',
                'description' => 'Full-rim oversized cat-eye optical frame; transparent smoke-gray or champagne/blush finish. Demo lens is marked TR90 100%.',
                'product_type' => 'frame',
                'variants' => [
                    [
                        'name' => 'Transparent Smoke Gray',
                        'sku' => 'FRM-SOFIA-2860-GRY',
                        'price' => 2500.00,
                        'attributes' => [
                            'color' => 'Transparent smoke gray',
                            'material' => 'TR90',
                            'lens_width' => 59,
                            'bridge' => 12,
                            'temple' => 145,
                        ],
                        'stock_quantity' => 4,
                        'low_stock_threshold' => 1,
                        'target_stock_level' => 5,
                    ],
                    [
                        'name' => 'Transparent Champagne / Blush',
                        'sku' => 'FRM-SOFIA-2860-CHAMP',
                        'price' => 2500.00,
                        'attributes' => [
                            'color' => 'Transparent champagne / blush',
                            'material' => 'TR90',
                            'lens_width' => 59,
                            'bridge' => 12,
                            'temple' => 145,
                        ],
                        'stock_quantity' => 3,
                        'low_stock_threshold' => 1,
                        'target_stock_level' => 5,
                    ],
                ],
            ],
            [
                'brand' => 'Mormaii',
                'category' => 'Sports Sunglasses',
                'name' => 'Mormaii Floater Street 280',
                'slug' => 'mormaii-floater-street-280',
                'description' => "Wraparound sports sunglasses with a glossy black frame and dark smoke lenses. Temple marking reads 'Tech Division 28021001 Floater Street'.",
                'product_type' => 'frame',
                'variants' => [
                    [
                        'name' => 'Black / Smoke',
                        'sku' => 'SUN-MORMAII-FLOATER280-BLK',
                        'price' => 650.00,
                        'attributes' => [
                            'color' => 'Black frame / smoke lens',
                            'material' => 'Plastic sports frame; exact polymer not confirmed',
                        ],
                        'stock_quantity' => 2,
                        'low_stock_threshold' => 1,
                        'target_stock_level' => 4,
                    ],
                ],
            ],
            [
                'brand' => 'ANTHOS',
                'category' => 'Optical Frame',
                'name' => 'ANTHOS MB 1399-A',
                'slug' => 'anthos-mb-1399-a',
                'description' => 'Full-rim rectangular optical frame with a dark tortoise pattern and amber highlights.',
                'product_type' => 'frame',
                'variants' => [
                    [
                        'name' => 'C4 Dark Tortoise',
                        'sku' => 'FRM-ANTHOS-MB1399A-C4',
                        'price' => 1800.00,
                        'attributes' => [
                            'color' => 'Dark tortoise / black / amber',
                            'material' => 'Plastic / acetate-style; exact material not marked',
                            'lens_width' => 54,
                            'bridge' => 18,
                            'temple' => 145,
                        ],
                        'stock_quantity' => 3,
                        'low_stock_threshold' => 1,
                        'target_stock_level' => 5,
                    ],
                ],
            ],
            [
                'brand' => "C'est Joli",
                'category' => 'Rimless Optical Frame',
                'name' => "C'est Joli 2860",
                'slug' => 'cest-joli-2860',
                'description' => 'Rimless rectangular optical frame with gold-tone bridge/temples, clear nose pads, and black temple tips.',
                'product_type' => 'frame',
                'variants' => [
                    [
                        'name' => 'C4 Gold / Black',
                        'sku' => 'FRM-CESTJOLI-2860-C4',
                        'price' => 2200.00,
                        'attributes' => [
                            'color' => 'Gold-tone / black',
                            'material' => 'Metal with plastic temple tips',
                            'lens_width' => 59,
                            'bridge' => 12,
                            'temple' => 145,
                        ],
                        'stock_quantity' => 2,
                        'low_stock_threshold' => 1,
                        'target_stock_level' => 4,
                    ],
                ],
            ],
            [
                'brand' => 'Unknown',
                'category' => 'Sports Optical Frame',
                'name' => 'Black/Red Sports Optical Frame',
                'slug' => 'black-red-sports-optical-frame',
                'description' => 'Full-rim wraparound sports optical frame with red nose/temple grip inserts and an oval O-style hinge logo. No reliable brand/model text is visible.',
                'product_type' => 'frame',
                'variants' => [
                    [
                        'name' => 'Black / Red',
                        'sku' => 'FRM-SPORT-BLKRED-001',
                        'price' => 1500.00,
                        'attributes' => [
                            'color' => 'Black / red',
                            'material' => 'Plastic frame with rubber-like grip inserts',
                        ],
                        'stock_quantity' => 2,
                        'low_stock_threshold' => 1,
                        'target_stock_level' => 4,
                    ],
                ],
            ],
            [
                'brand' => 'New Look',
                'category' => 'Contact Lens Care',
                'name' => 'New Look Multi-Purpose All-In-One Solution',
                'slug' => 'new-look-multi-purpose-all-in-one-solution',
                'description' => 'Sterile multi-purpose contact lens solution; package states it removes lipid build-up, cleans and disinfects lenses, keeps lenses moist, and relieves dryness/irritation.',
                'product_type' => 'accessory',
                'variants' => [
                    [
                        'name' => 'New Extra Comfort Formula - 90 mL',
                        'sku' => 'ACC-NEWLOOK-MPS-90ML',
                        'price' => 350.00,
                        'attributes' => [
                            'volume_ml' => 90,
                            'package_size' => '90 mL',
                            'material' => 'Multi-purpose contact lens solution',
                        ],
                        'stock_quantity' => 12,
                        'low_stock_threshold' => 3,
                        'target_stock_level' => 18,
                    ],
                ],
            ],
            [
                'brand' => 'Systane / Alcon',
                'category' => 'Eye Drops',
                'name' => 'Systane Complete Preservative-Free Lubricant Eye Drops',
                'slug' => 'systane-complete-preservative-free-lubricant-eye-drops',
                'description' => 'Preservative-free lubricant eye drops marketed for all-in-one dry eye relief.',
                'product_type' => 'accessory',
                'variants' => [
                    [
                        'name' => 'Preservative-Free - 10 mL',
                        'sku' => 'ACC-SYSTANE-COMPLETE-PF-10ML',
                        'price' => 850.00,
                        'attributes' => [
                            'volume_ml' => 10,
                            'package_size' => '10 mL',
                            'material' => 'Lubricant eye drops',
                        ],
                        'stock_quantity' => 10,
                        'low_stock_threshold' => 3,
                        'target_stock_level' => 15,
                    ],
                ],
            ],
            [
                'brand' => 'Systane / Alcon',
                'category' => 'Eye Drops',
                'name' => 'Systane Hydration Preservative-Free Lubricant Eye Drops',
                'slug' => 'systane-hydration-preservative-free-lubricant-eye-drops',
                'description' => 'Preservative-free lubricant eye drops marketed for long-lasting dry eye relief.',
                'product_type' => 'accessory',
                'variants' => [
                    [
                        'name' => 'Hydration Preservative-Free - 10 mL',
                        'sku' => 'ACC-SYSTANE-HYDRATION-PF-10ML',
                        'price' => 750.00,
                        'attributes' => [
                            'volume_ml' => 10,
                            'package_size' => '10 mL',
                            'material' => 'Lubricant eye drops',
                        ],
                        'stock_quantity' => 10,
                        'low_stock_threshold' => 3,
                        'target_stock_level' => 15,
                    ],
                ],
            ],
            [
                'brand' => 'Systane / Alcon',
                'category' => 'Eye Drops',
                'name' => 'Systane Ultra Preservative-Free Lubricant Eye Drops',
                'slug' => 'systane-ultra-preservative-free-lubricant-eye-drops',
                'description' => 'Preservative-free lubricant eye drops marketed for fast-acting dry eye relief and extended protection.',
                'product_type' => 'accessory',
                'variants' => [
                    [
                        'name' => 'Ultra Preservative-Free - 10 mL',
                        'sku' => 'ACC-SYSTANE-ULTRA-PF-10ML',
                        'price' => 650.00,
                        'attributes' => [
                            'volume_ml' => 10,
                            'package_size' => '10 mL',
                            'material' => 'Lubricant eye drops',
                        ],
                        'stock_quantity' => 10,
                        'low_stock_threshold' => 3,
                        'target_stock_level' => 15,
                    ],
                ],
            ],
            [
                'brand' => 'EnSight / Cipla Health',
                'category' => 'Eye Drops',
                'name' => 'Lacryl Hydrate Eye Drops',
                'slug' => 'lacryl-hydrate-eye-drops',
                'description' => 'Advanced ocular lubrication for long-lasting dry eye relief; package highlights post-operative/post-LASIK use, moderate-to-severe dry eyes and digital eye strain.',
                'product_type' => 'accessory',
                'variants' => [
                    [
                        'name' => '10 mL',
                        'sku' => 'ACC-LACRYL-HYDRATE-10ML',
                        'price' => 450.00,
                        'attributes' => [
                            'volume_ml' => 10,
                            'package_size' => '10 mL',
                            'material' => 'Lubricant eye drops',
                        ],
                        'stock_quantity' => 10,
                        'low_stock_threshold' => 3,
                        'target_stock_level' => 15,
                    ],
                ],
            ],
            [
                'brand' => 'Alcon',
                'category' => 'Colored Contact Lens',
                'name' => 'AIR OPTIX COLORS',
                'slug' => 'air-optix-colors',
                'description' => 'Monthly replacement colored silicone-hydrogel contact lens with 3-in-1 Color Technology; daily wear only and removed for cleaning/disinfection between uses. Actual power, lot, expiry, and received quantity are not yet verified.',
                'product_type' => 'contact_lens',
                'variants' => array_map(
                    fn (string $color): array => [
                        'name' => $color,
                        'sku' => 'CL-ALCON-AOC2-'.strtoupper(str_replace(' ', '-', $color)),
                        'price' => 1500.00,
                        'attributes' => [
                            'base_curve' => 8.6,
                            'diameter' => 14.2,
                            'color' => $color,
                            'pack_size' => 2,
                        ],
                        'stock_quantity' => 0,
                        'low_stock_threshold' => 0,
                        'target_stock_level' => null,
                    ],
                    [
                        'Brown',
                        'Pure Hazel',
                        'Amethyst',
                        'Blue',
                        'Green',
                        'Gray',
                        'Honey',
                        'Brilliant Blue',
                        'True Sapphire',
                        'Turquoise',
                        'Gemstone Green',
                        'Sterling Gray',
                    ],
                ),
            ],
        ] as $productData) {
            $this->upsertClinicProduct($productData, $brands, $categories);
        }
    }

    /**
     * @param  array{brand: string, category: string, name: string, slug: string, description: string, product_type: string, variants: array<int, array<string, mixed>>}  $productData
     * @param  array<string, Brand>  $brands
     * @param  array<string, ProductCategory>  $categories
     */
    private function upsertClinicProduct(array $productData, array $brands, array $categories): void
    {
        $productImages = $this->copySeededImages('products', $productData['slug']);

        $product = Product::query()->updateOrCreate(
            ['slug' => $productData['slug']],
            [
                'brand_id' => $brands[$productData['brand']]->id,
                'category_id' => $categories[$productData['category']]->id,
                'name' => $productData['name'],
                'description' => $productData['description'],
                'is_active' => true,
                'product_type' => $productData['product_type'],
                'images' => $productImages,
            ],
        );

        $primaryVariantImages = [];

        foreach ($productData['variants'] as $variantData) {
            $variantImages = $this->copySeededImages('variants', $variantData['sku']);

            if ($primaryVariantImages === [] && $variantImages !== []) {
                $primaryVariantImages = $variantImages;
            }

            ProductVariant::query()->updateOrCreate(
                ['sku' => $variantData['sku']],
                [
                    'product_id' => $product->id,
                    'name' => $variantData['name'],
                    'is_active' => true,
                    'price' => $variantData['price'],
                    'compare_at_price' => null,
                    'cost_price' => null,
                    'attributes' => $variantData['attributes'] ?? [],
                    'stock_quantity' => $variantData['stock_quantity'],
                    'low_stock_threshold' => $variantData['low_stock_threshold'],
                    'target_stock_level' => $variantData['target_stock_level'] ?? null,
                    'ar_eligible' => false,
                    'ar_asset_reference' => null,
                    'images' => $variantImages,
                ],
            );
        }

        if ($productImages === [] && $primaryVariantImages !== []) {
            $product->update(['images' => $primaryVariantImages]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function copySeededImages(string $collection, string $identifier): array
    {
        $sourceDirectory = database_path("seeders/data/clinic-product-images/{$collection}/{$identifier}");

        if (! is_dir($sourceDirectory)) {
            return [];
        }

        $disk = Storage::disk('public');

        return collect(File::files($sourceDirectory))
            ->filter(fn (\SplFileInfo $file): bool => in_array(
                strtolower($file->getExtension()),
                ['jpg', 'jpeg', 'png', 'webp'],
                true,
            ))
            ->sortBy(fn (\SplFileInfo $file): string => $file->getFilename(), SORT_NATURAL)
            ->map(function (\SplFileInfo $file) use ($collection, $identifier, $disk): string {
                $relativePath = "{$collection}/{$identifier}/{$file->getFilename()}";

                $disk->put($relativePath, File::get($file->getPathname()));

                return $relativePath;
            })
            ->values()
            ->all();
    }

    private function retireLegacyCatalog(): void
    {
        $legacyProductSlugs = [
            'acuvue-oasys',
            'lens-cleaning-kit',
            'hard-shell-glasses-case',
            'microfiber-cleaning-cloth',
            'classic-rectangle-frame',
            'round-metal-frame',
            'aviator-sunglass-frame',
        ];

        $legacyVariantSkus = [
            'ACOASYS-200-6PK',
            'ACOASYS-TORIC-300-125-180',
            'LCK-STD-001',
            'LCK-TRV-001',
            'HSC-STD-001',
            'MCC-STD-001',
            'CRF-BLK-001',
            'CRF-TRT-001',
            'RMF-GLD-001',
            'RMF-SLV-001',
            'ASF-GLD-001',
        ];

        Product::query()
            ->whereIn('slug', $legacyProductSlugs)
            ->update(['is_active' => false]);

        ProductVariant::query()
            ->whereIn('sku', $legacyVariantSkus)
            ->update(['is_active' => false]);

        Brand::query()
            ->whereIn('name', ['VisionCraft'])
            ->update(['is_active' => false]);

        ProductCategory::query()
            ->whereIn('name', [
                'Full Rim',
                'Sunglasses',
                'Daily Disposable',
                'Toric',
                'Lens Care',
                'Cases & Storage',
                'Reading Glasses (Discontinued)',
            ])
            ->update(['is_active' => false]);
    }
}
