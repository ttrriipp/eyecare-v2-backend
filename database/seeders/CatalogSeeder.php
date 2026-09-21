<?php

namespace Database\Seeders;

use App\Enums\ProductUsage;
use App\Models\Brand;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Seeds the clinic's current physical catalog.
 *
 * Prices, stock quantities, and opening lots are provisional local-development
 * values where the workbook did not provide verified figures. Opening receipt
 * movements and batches mirror those provisional quantities for the initial
 * inventory view.
 */
class CatalogSeeder extends Seeder
{
    private const string LEGACY_OPENING_STOCK_DATE = '2026-09-01';

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

        collect([
            [
                'name' => 'Anti-Reflective',
                'description' => 'Reduces glare and improves visual clarity on prescription lenses.',
                'price' => 1000.00,
            ],
            [
                'name' => 'Photochromic Treatment',
                'description' => 'Automatically darkens in bright light and returns to a clear state indoors.',
                'price' => 2500.00,
            ],
            [
                'name' => 'Polarized Lens Treatment',
                'description' => 'Reduces glare from bright outdoor surfaces for more comfortable daylight wear.',
                'price' => 1800.00,
            ],
            [
                'name' => 'Scratch-Resistant Coating',
                'description' => 'Adds a durable protective coating to help reduce everyday surface scratches.',
                'price' => 500.00,
            ],
            [
                'name' => 'Tinted Lens Treatment',
                'description' => 'Applies a cosmetic or light-filtering tint selected for the patient’s eyewear.',
                'price' => 700.00,
            ],
            [
                'name' => 'UV Protection',
                'description' => 'Adds ultraviolet light protection to the selected prescription lens package.',
                'price' => 400.00,
            ],
        ])->each(fn (array $attributes) => LensOption::query()->firstOrCreate(
            ['name' => $attributes['name']],
            [
                'description' => $attributes['description'],
                'price' => $attributes['price'],
                'is_active' => true,
            ],
        ));

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
        $openingStockReceiverId = $this->openingStockReceiverId();

        foreach ([
            'SOFIA EYEWEAR',
            'Mormaii',
            'ANTHOS',
            "C'est Joli",
            'Unknown',
            'Nike',
            "Gino's Collection",
            'Polo Fashion',
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
                'default_variant_attributes' => [
                    'material' => 'TR90',
                    'lens_width' => 59,
                    'bridge' => 12,
                    'temple' => 145,
                ],
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
                        'opening_stock' => [
                            'quantity' => 4,
                            'purchased_at' => '2026-09-01',
                        ],
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
                        'opening_stock' => [
                            'quantity' => 3,
                            'purchased_at' => '2026-09-02',
                        ],
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
                'default_variant_attributes' => [
                    'color' => 'Black frame / smoke lens',
                    'material' => 'Plastic',
                ],
                'variants' => [
                    [
                        'name' => 'Black / Smoke',
                        'sku' => 'SUN-MORMAII-FLOATER280-BLK',
                        'price' => 650.00,
                        'attributes' => [
                            'color' => 'Black frame / smoke lens',
                            'material' => 'Plastic',
                        ],
                        'stock_quantity' => 2,
                        'opening_stock' => [
                            'quantity' => 2,
                            'purchased_at' => '2026-09-03',
                        ],
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
                'default_variant_attributes' => [
                    'color' => 'Dark tortoise / black / amber',
                    'material' => 'Plastic',
                    'lens_width' => 54,
                    'bridge' => 18,
                    'temple' => 145,
                ],
                'variants' => [
                    [
                        'name' => 'C4 Dark Tortoise',
                        'sku' => 'FRM-ANTHOS-MB1399A-C4',
                        'price' => 1800.00,
                        'attributes' => [
                            'color' => 'Dark tortoise / black / amber',
                            'material' => 'Plastic',
                            'lens_width' => 54,
                            'bridge' => 18,
                            'temple' => 145,
                        ],
                        'stock_quantity' => 3,
                        'opening_stock' => [
                            'quantity' => 3,
                            'purchased_at' => '2026-09-04',
                        ],
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
                'default_variant_attributes' => [
                    'color' => 'Gold-tone / black',
                    'material' => 'Metal',
                    'lens_width' => 59,
                    'bridge' => 12,
                    'temple' => 145,
                ],
                'variants' => [
                    [
                        'name' => 'C4 Gold / Black',
                        'sku' => 'FRM-CESTJOLI-2860-C4',
                        'price' => 2200.00,
                        'attributes' => [
                            'color' => 'Gold-tone / black',
                            'material' => 'Metal',
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
                'default_variant_attributes' => [
                    'color' => 'Black / red',
                    'material' => 'Plastic',
                ],
                'variants' => [
                    [
                        'name' => 'Black / Red',
                        'sku' => 'FRM-SPORT-BLKRED-001',
                        'price' => 1500.00,
                        'attributes' => [
                            'color' => 'Black / red',
                            'material' => 'Plastic',
                        ],
                        'stock_quantity' => 2,
                        'low_stock_threshold' => 1,
                        'target_stock_level' => 4,
                    ],
                ],
            ],
            [
                'brand' => 'Unknown',
                'category' => 'Optical Frame',
                'name' => 'Model 8763 Optical Frame',
                'slug' => 'model-8763-optical-frame',
                'description' => 'Full-rim rectangular/square optical frame with gold chevron-style temple accents.',
                'product_type' => 'frame',
                'default_variant_attributes' => [
                    'color' => 'Black / Gold',
                    'material' => 'Plastic',
                    'lens_width' => 54,
                    'bridge' => 18,
                    'temple' => 150,
                    'model_code' => '8763',
                    'color_code' => 'C2',
                ],
                'variants' => [
                    [
                        'name' => 'Black / Gold - C2',
                        'sku' => 'FRAME-8763-C2',
                        'price' => 2500.00,
                        'attributes' => [
                            'color' => 'Black / Gold',
                            'material' => 'Plastic',
                            'lens_width' => 54,
                            'bridge' => 18,
                            'temple' => 150,
                            'model_code' => '8763',
                            'color_code' => 'C2',
                        ],
                        'stock_quantity' => 2,
                        'low_stock_threshold' => 1,
                        'target_stock_level' => 4,
                    ],
                ],
            ],
            [
                'brand' => 'Nike',
                'category' => 'Optical Frame',
                'name' => 'Nike 5753 Optical Frame',
                'slug' => 'nike-5753-optical-frame',
                'description' => 'Full-rim rounded optical frame with Nike swoosh branding.',
                'product_type' => 'frame',
                'default_variant_attributes' => [
                    'color' => 'Black',
                    'material' => 'Plastic',
                    'lens_width' => 49,
                    'bridge' => 21,
                    'temple' => 145,
                    'model_code' => '5753',
                ],
                'variants' => [
                    [
                        'name' => 'Black',
                        'sku' => 'NIKE-5753-BLK',
                        'price' => 4500.00,
                        'attributes' => [
                            'color' => 'Black',
                            'material' => 'Plastic',
                            'lens_width' => 49,
                            'bridge' => 21,
                            'temple' => 145,
                            'model_code' => '5753',
                        ],
                        'stock_quantity' => 2,
                        'low_stock_threshold' => 1,
                        'target_stock_level' => 4,
                    ],
                ],
            ],
            [
                'brand' => 'SOFIA EYEWEAR',
                'category' => 'Optical Frame',
                'name' => 'Sofia Eyewear 52103 Optical Frame',
                'slug' => 'sofia-eyewear-52103-optical-frame',
                'description' => 'Full-rim transparent/clear rectangular optical frame.',
                'product_type' => 'frame',
                'default_variant_attributes' => [
                    'color' => 'Clear / Transparent',
                    'material' => 'Plastic',
                    'lens_width' => 56,
                    'bridge' => 17,
                    'temple' => 148,
                    'model_code' => '52103',
                    'color_code' => 'C7',
                ],
                'variants' => [
                    [
                        'name' => 'Clear - C7',
                        'sku' => 'SOFIA-52103-C7',
                        'price' => 2500.00,
                        'attributes' => [
                            'color' => 'Clear / Transparent',
                            'material' => 'Plastic',
                            'lens_width' => 56,
                            'bridge' => 17,
                            'temple' => 148,
                            'model_code' => '52103',
                            'color_code' => 'C7',
                        ],
                        'stock_quantity' => 2,
                        'low_stock_threshold' => 1,
                        'target_stock_level' => 4,
                    ],
                ],
            ],
            [
                'brand' => 'Polo Fashion',
                'category' => 'Optical Frame',
                'name' => 'Polo Fashion P002 Optical Frame',
                'slug' => 'polo-fashion-p002-optical-frame',
                'description' => 'Full-rim rounded/polygonal metal optical frame with adjustable nose pads.',
                'product_type' => 'frame',
                'default_variant_attributes' => [
                    'color' => 'Dark Gunmetal / Black',
                    'material' => 'Metal',
                    'lens_width' => 53,
                    'bridge' => 18,
                    'temple' => 142,
                    'model_code' => 'P002',
                ],
                'variants' => [
                    [
                        'name' => 'Dark Gunmetal / Black',
                        'sku' => 'POLO-P002-DGM',
                        'price' => 1999.00,
                        'attributes' => [
                            'color' => 'Dark Gunmetal / Black',
                            'material' => 'Metal',
                            'lens_width' => 53,
                            'bridge' => 18,
                            'temple' => 142,
                            'model_code' => 'P002',
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
                'default_variant_attributes' => [
                    'volume_ml' => '90',
                    'package_size' => '90 mL',
                    'material' => 'Multi-purpose contact lens solution',
                ],
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
                        'stock_quantity' => 9,
                        'low_stock_threshold' => 3,
                        'target_stock_level' => 18,
                        'opening_stock' => [
                            'quantity' => 9,
                            'lot_number' => 'ACC-NEWLOOK-MPS-202609',
                            'expires_on' => '2027-09-30',
                            'purchased_at' => '2026-09-01',
                        ],
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
                'default_variant_attributes' => [
                    'volume_ml' => '10',
                    'package_size' => '10 mL',
                    'material' => 'Lubricant eye drops',
                ],
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
                        'stock_quantity' => 8,
                        'low_stock_threshold' => 3,
                        'target_stock_level' => 15,
                        'opening_stock' => [
                            'quantity' => 8,
                            'lot_number' => 'ACC-SYSTANE-COMPLETE-202609',
                            'expires_on' => '2027-07-31',
                            'purchased_at' => '2026-09-01',
                        ],
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
                'default_variant_attributes' => [
                    'volume_ml' => '10',
                    'package_size' => '10 mL',
                    'material' => 'Lubricant eye drops',
                ],
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
                        'stock_quantity' => 7,
                        'low_stock_threshold' => 3,
                        'target_stock_level' => 15,
                        'opening_stock' => [
                            'quantity' => 7,
                            'lot_number' => 'ACC-SYSTANE-HYDRATION-202609',
                            'expires_on' => '2027-07-31',
                            'purchased_at' => '2026-09-01',
                        ],
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
                'default_variant_attributes' => [
                    'volume_ml' => '10',
                    'package_size' => '10 mL',
                    'material' => 'Lubricant eye drops',
                ],
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
                        'stock_quantity' => 6,
                        'low_stock_threshold' => 3,
                        'target_stock_level' => 15,
                        'opening_stock' => [
                            'quantity' => 6,
                            'lot_number' => 'ACC-SYSTANE-ULTRA-202609',
                            'expires_on' => '2027-06-30',
                            'purchased_at' => '2026-09-01',
                        ],
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
                'default_variant_attributes' => [
                    'volume_ml' => '10',
                    'package_size' => '10 mL',
                    'material' => 'Lubricant eye drops',
                ],
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
                        'stock_quantity' => 5,
                        'low_stock_threshold' => 3,
                        'target_stock_level' => 15,
                        'opening_stock' => [
                            'quantity' => 5,
                            'lot_number' => 'ACC-LACRYL-HYDRATE-202609',
                            'expires_on' => '2027-06-30',
                            'purchased_at' => '2026-09-01',
                        ],
                    ],
                ],
            ],
            [
                'brand' => 'Alcon',
                'category' => 'Colored Contact Lens',
                'name' => 'AIR OPTIX COLORS',
                'slug' => 'air-optix-colors',
                'description' => 'Monthly replacement colored silicone-hydrogel contact lens with 3-in-1 Color Technology; daily wear only and removed for cleaning/disinfection between uses. Actual power values are not yet verified; seeded inventory values are provisional demo data.',
                'product_type' => 'contact_lens',
                'default_variant_attributes' => [
                    'base_curve' => '8.6',
                    'diameter' => '14.2',
                    'pack_size' => '2',
                ],
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
                        'stock_quantity' => 6,
                        'low_stock_threshold' => 2,
                        'target_stock_level' => 12,
                        'opening_stock' => [
                            'quantity' => 6,
                            'lot_number' => 'CL-ALCON-AOC2-'.strtoupper(str_replace(' ', '-', $color)).'-202609',
                            'expires_on' => '2027-09-30',
                            'purchased_at' => '2026-09-01',
                        ],
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
            $this->upsertClinicProduct($productData, $brands, $categories, $openingStockReceiverId);
        }
    }

    /**
     * @param  array{brand: string, category: string, name: string, slug: string, description: string, product_type: string, variants: array<int, array<string, mixed>>}  $productData
     * @param  array<string, Brand>  $brands
     * @param  array<string, ProductCategory>  $categories
     */
    private function upsertClinicProduct(
        array $productData,
        array $brands,
        array $categories,
        ?int $openingStockReceiverId,
    ): void {
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
                'usage' => $productData['usage'] ?? match ($productData['product_type']) {
                    'contact_lens', 'accessory' => ProductUsage::OneMonth,
                    default => null,
                },
                'images' => $productImages,
                'default_variant_attributes' => $productData['default_variant_attributes'] ?? null,
            ],
        );

        $primaryVariantImages = [];

        foreach ($productData['variants'] as $variantData) {
            $variantImages = $this->copySeededImages('variants', $variantData['sku']);

            if ($primaryVariantImages === [] && $variantImages !== []) {
                $primaryVariantImages = $variantImages;
            }

            $variant = ProductVariant::query()->updateOrCreate(
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

            $openingStock = $variantData['opening_stock'] ?? null;

            if (
                $openingStock === null
                && $productData['product_type'] === 'frame'
                && (int) ($variantData['stock_quantity'] ?? 0) > 0
            ) {
                $openingStock = [
                    'quantity' => (int) $variantData['stock_quantity'],
                    'purchased_at' => self::LEGACY_OPENING_STOCK_DATE,
                ];
            }

            if (is_array($openingStock) && isset($openingStock['quantity'], $openingStock['purchased_at'])) {
                $this->seedOpeningStockMovement($variant, $openingStock, $openingStockReceiverId);
            }
        }

        if ($productImages === [] && $primaryVariantImages !== []) {
            $product->update(['images' => $primaryVariantImages]);
        }
    }

    /**
     * @param  array{quantity: int, purchased_at: string, lot_number?: string, expires_on?: string|null}  $openingStock
     */
    private function seedOpeningStockMovement(
        ProductVariant $variant,
        array $openingStock,
        ?int $receivedByUserId,
    ): void {
        $restockType = InventoryMovementType::query()->firstOrCreate(['name' => 'restock']);
        $openingBatch = $this->seedOpeningStockBatch($variant, $openingStock, $receivedByUserId);

        InventoryMovement::query()->updateOrCreate(
            [
                'product_variant_id' => $variant->id,
                'inventory_movement_type_id' => $restockType->id,
                'notes' => 'Opening stock seeded from catalog.',
            ],
            [
                'inventory_lot_id' => $openingBatch?->id,
                'quantity_change' => $openingStock['quantity'],
                'purchased_at' => $openingStock['purchased_at'],
                'previous_stock' => 0,
                'new_stock' => $openingStock['quantity'],
                'created_by' => null,
            ],
        );
    }

    /**
     * @param  array{quantity: int, purchased_at: string, lot_number?: string, expires_on?: string|null}  $openingStock
     */
    private function seedOpeningStockBatch(
        ProductVariant $variant,
        array $openingStock,
        ?int $receivedByUserId,
    ): ?InventoryLot {
        if ($receivedByUserId === null) {
            return null;
        }

        $batchNumber = $openingStock['lot_number']
            ?? $this->openingStockBatchNumber($variant, $openingStock['purchased_at']);
        $canonicalBatchExists = InventoryLot::query()
            ->where('product_variant_id', $variant->id)
            ->where('lot_number', $batchNumber)
            ->exists();

        if (! $canonicalBatchExists) {
            InventoryLot::query()
                ->where('product_variant_id', $variant->id)
                ->where('lot_number', 'OPENING-'.$variant->sku)
                ->update(['lot_number' => $batchNumber]);
        }

        return InventoryLot::query()->updateOrCreate(
            [
                'product_variant_id' => $variant->id,
                'lot_number' => $batchNumber,
            ],
            [
                'expires_on' => $openingStock['expires_on'] ?? null,
                'received_quantity' => $openingStock['quantity'],
                'quantity_on_hand' => $openingStock['quantity'],
                'received_at' => CarbonImmutable::parse($openingStock['purchased_at'])->startOfDay(),
                'purchased_at' => $openingStock['purchased_at'],
                'received_by' => $receivedByUserId,
                'source_reference' => 'Catalog opening stock',
            ],
        );
    }

    private function openingStockBatchNumber(ProductVariant $variant, string $purchasedAt): string
    {
        return sprintf(
            'FRM-%d-%s-1',
            $variant->id,
            CarbonImmutable::parse($purchasedAt)->format('ymd'),
        );
    }

    private function openingStockReceiverId(): ?int
    {
        $receiverId = User::query()
            ->where('is_active', true)
            ->whereHas(
                'roles',
                fn (Builder $roleQuery): Builder => $roleQuery->whereIn('name', ['admin', 'staff', 'optometrist']),
            )
            ->orderBy('id')
            ->value('id');

        return $receiverId === null ? null : (int) $receiverId;
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

        $disk = Storage::disk((string) config('filesystems.catalog_disk'));

        return collect(File::files($sourceDirectory))
            ->filter(fn (\SplFileInfo $file): bool => in_array(
                strtolower($file->getExtension()),
                ['jpg', 'jpeg', 'png', 'webp'],
                true,
            ))
            ->sortBy(fn (\SplFileInfo $file): string => $file->getFilename(), SORT_NATURAL)
            ->map(function (\SplFileInfo $file) use ($collection, $identifier, $disk): string {
                $relativePath = "{$collection}/{$identifier}/{$file->getFilename()}";

                if (! $disk->put($relativePath, File::get($file->getPathname()))) {
                    throw new RuntimeException("Unable to write seeded catalog image [{$relativePath}].");
                }

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
