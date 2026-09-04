<?php

use App\Enums\ArAssetStatus;
use App\Models\ArAsset;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\ArAssetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('ar_quarantine');
    Storage::fake('ar_published');
    config([
        'ar.assets.quarantine_disk' => 'ar_quarantine',
        'ar.assets.published_disk' => 'ar_published',
        'ar.assets.base_url' => 'https://cdn.example.com',
    ]);

    $category = ProductCategory::factory()->create([
        'name' => 'AR seeder test category '.fake()->uuid(),
    ]);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'product_type' => 'frame',
    ]);

    $this->variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'FRM-ANTHOS-MB1399A-C4',
        'name' => 'C4 Dark Tortoise',
        'attributes' => [
            'color' => 'Dark tortoise / black / amber',
            'material' => 'Plastic / acetate-style; exact material not marked',
            'lens_width' => 54,
            'bridge' => 18,
            'temple' => 145,
        ],
    ]);

    $this->actor = User::factory()->staff()->create([
        'email' => 'staff@eyecare.test',
    ]);
});

test('the tortoise fixture is published with its physical calibration', function (): void {
    (new ArAssetSeeder)->run();

    $asset = ArAsset::query()
        ->where('product_variant_id', $this->variant->id)
        ->sole();

    expect($asset->status)->toBe(ArAssetStatus::Published)
        ->and($asset->version)->toBe(1)
        ->and($asset->calibration)->toMatchArray([
            'frame_width_mm' => 138.0,
            'outer_frame_height_mm' => 45.0,
            'lens_width_mm' => 54.0,
            'lens_height_mm' => 40.0,
            'bridge_width_mm' => 18.0,
            'temple_length_mm' => 145.0,
            'scale' => ['x' => 0.185, 'y' => 0.185, 'z' => 0.185],
            'anchor' => ['x' => -0.0075, 'y' => 0.026, 'z' => 0.0],
            'rotation_degrees' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        ])
        ->and($asset->isPatientReady())->toBeTrue();

    Storage::disk('ar_published')->assertExists($asset->published_path);
    expect($this->variant->fresh()->published_ar_asset_id)->toBe($asset->id);
});

test('running the tortoise seeder again does not create another version', function (): void {
    $seeder = new ArAssetSeeder;

    $seeder->run();
    $firstAsset = ArAsset::query()
        ->where('product_variant_id', $this->variant->id)
        ->sole();

    $seeder->run();

    expect(ArAsset::query()->where('product_variant_id', $this->variant->id)->count())->toBe(1)
        ->and($this->variant->fresh()->published_ar_asset_id)->toBe($firstAsset->id);
});

test('an unreferenced publication path is preserved while the fixture gets a free version', function (): void {
    $sourcePath = database_path('seeders/data/clinic-ar-assets/frame-002-tortoise-rectangle-v2.glb');
    $stalePath = 'variants/'.$this->variant->id.'/v1/model.glb';

    Storage::disk('ar_published')->put($stalePath, File::get($sourcePath));

    (new ArAssetSeeder)->run();

    $asset = ArAsset::query()
        ->where('product_variant_id', $this->variant->id)
        ->sole();

    expect($asset->status)->toBe(ArAssetStatus::Published)
        ->and($asset->version)->toBe(2)
        ->and($asset->published_path)->toBe('variants/'.$this->variant->id.'/v2/model.glb');

    Storage::disk('ar_published')->assertExists($stalePath);
});

test('a conflicting orphaned publication path is skipped without being overwritten', function (): void {
    $stalePath = 'variants/'.$this->variant->id.'/v1/model.glb';
    $legacyContents = 'legacy publication';

    Storage::disk('ar_published')->put($stalePath, $legacyContents);

    (new ArAssetSeeder)->run();

    $asset = ArAsset::query()
        ->where('product_variant_id', $this->variant->id)
        ->sole();

    expect($asset->status)->toBe(ArAssetStatus::Published)
        ->and($asset->version)->toBe(2)
        ->and($asset->published_path)->toBe('variants/'.$this->variant->id.'/v2/model.glb')
        ->and(Storage::disk('ar_published')->get($stalePath))->toBe($legacyContents);
});

test('the seeded tortoise is exposed through the frame detail API', function (): void {
    (new ArAssetSeeder)->run();

    $asset = ArAsset::query()
        ->where('product_variant_id', $this->variant->id)
        ->sole();

    $this->actingAs($this->actor)
        ->getJson("/api/v1/frames/{$this->variant->product_id}")
        ->assertOk()
        ->assertJsonPath('data.variants.0.sku', 'FRM-ANTHOS-MB1399A-C4')
        ->assertJsonPath('data.variants.0.ar.status', 'ready')
        ->assertJsonPath('data.variants.0.ar.asset.url', $asset->url)
        ->assertJsonPath('data.variants.0.ar.asset.version', 1)
        ->assertJsonPath('data.variants.0.ar.calibration.frame_width_mm', 138)
        ->assertJsonPath('data.variants.0.ar.calibration.lens_height_mm', 40);
});
