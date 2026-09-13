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

    $mormaiiProduct = Product::factory()->create([
        'category_id' => $category->id,
        'product_type' => 'frame',
    ]);
    $this->mormaiiVariant = ProductVariant::factory()->for($mormaiiProduct)->create([
        'sku' => 'SUN-MORMAII-FLOATER280-BLK',
        'name' => 'Black / Smoke',
        'attributes' => [
            'color' => 'Black frame / smoke lens',
            'material' => 'Plastic',
        ],
    ]);

    $model8763Product = Product::factory()->create([
        'category_id' => $category->id,
        'product_type' => 'frame',
    ]);
    $this->model8763Variant = ProductVariant::factory()->for($model8763Product)->create([
        'sku' => 'FRAME-8763-C2',
        'name' => 'Black / Gold - C2',
        'attributes' => [
            'color' => 'Black / Gold',
            'material' => 'Plastic',
            'lens_width' => 54,
            'bridge' => 18,
            'temple' => 150,
            'model_code' => '8763',
            'color_code' => 'C2',
        ],
    ]);

    $nikeProduct = Product::factory()->create([
        'category_id' => $category->id,
        'product_type' => 'frame',
    ]);
    $this->nikeVariant = ProductVariant::factory()->for($nikeProduct)->create([
        'sku' => 'NIKE-5753-BLK',
        'name' => 'Black',
        'attributes' => [
            'color' => 'Black',
            'material' => 'Plastic',
            'lens_width' => 49,
            'bridge' => 21,
            'temple' => 145,
            'model_code' => '5753',
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
            'scale' => ['x' => 0.20, 'y' => 0.20, 'z' => 0.20],
            'anchor' => ['x' => -0.0075, 'y' => 0.031, 'z' => 0.0],
            'rotation_degrees' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        ])
        ->and($asset->isPatientReady())->toBeTrue();

    Storage::disk('ar_published')->assertExists($asset->published_path);
    expect($this->variant->fresh()->published_ar_asset_id)->toBe($asset->id);
});

test('model 8763 is seeded with the tortoise calibration preset', function (): void {
    (new ArAssetSeeder)->run();

    $asset = ArAsset::query()
        ->where('product_variant_id', $this->model8763Variant->id)
        ->sole();

    expect($asset->status)->toBe(ArAssetStatus::Published)
        ->and($asset->version)->toBe(1)
        ->and($asset->sha256)->toBe(hash_file(
            'sha256',
            database_path('seeders/data/clinic-ar-assets/frame-007-black-square.glb'),
        ))
        ->and($asset->calibration)->toMatchArray(config('ar.presets.round_frame.calibration'));

    Storage::disk('ar_published')->assertExists($asset->published_path);
    expect($this->model8763Variant->fresh()->published_ar_asset_id)->toBe($asset->id);
});

test('the Nike black round fixture is published with the tortoise calibration preset', function (): void {
    (new ArAssetSeeder)->run();

    $asset = ArAsset::query()
        ->where('product_variant_id', $this->nikeVariant->id)
        ->sole();

    expect($asset->status)->toBe(ArAssetStatus::Published)
        ->and($asset->version)->toBe(1)
        ->and($asset->sha256)->toBe(hash_file(
            'sha256',
            database_path('seeders/data/clinic-ar-assets/frame-009-black-round.glb'),
        ))
        ->and($asset->calibration)->toMatchArray(config('ar.presets.round_frame.calibration'));

    Storage::disk('ar_published')->assertExists($asset->published_path);
    expect($this->nikeVariant->fresh()->published_ar_asset_id)->toBe($asset->id);
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

test('the Mormaii fixture is published with its own preset calibration', function (): void {
    (new ArAssetSeeder)->run();

    $asset = ArAsset::query()
        ->where('product_variant_id', $this->mormaiiVariant->id)
        ->sole();

    expect($asset->status)->toBe(ArAssetStatus::Published)
        ->and($asset->version)->toBe(1)
        ->and($asset->sha256)->toBe(hash_file(
            'sha256',
            database_path('seeders/data/clinic-ar-assets/frame-004-black-wraparound.glb'),
        ))
        ->and($asset->calibration)->toMatchArray([
            'frame_width_mm' => 138.0,
            'outer_frame_height_mm' => 45.0,
            'lens_width_mm' => 54.0,
            'lens_height_mm' => 40.0,
            'bridge_width_mm' => 18.0,
            'temple_length_mm' => 145.0,
            'scale' => ['x' => 0.205, 'y' => 0.205, 'z' => 0.205],
            'anchor' => ['x' => -0.001, 'y' => -0.031, 'z' => 0.0],
            'rotation_degrees' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        ]);

    Storage::disk('ar_published')->assertExists($asset->published_path);
    expect($this->mormaiiVariant->fresh()->published_ar_asset_id)->toBe($asset->id);

    $this->actingAs($this->actor)
        ->getJson("/api/v1/frames/{$this->mormaiiVariant->product_id}")
        ->assertOk()
        ->assertJsonPath('data.variants.0.sku', 'SUN-MORMAII-FLOATER280-BLK')
        ->assertJsonPath('data.variants.0.ar.status', 'ready')
        ->assertJsonPath('data.variants.0.ar.asset.version', 1)
        ->assertJsonPath('data.variants.0.ar.asset.url', $asset->url);
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
