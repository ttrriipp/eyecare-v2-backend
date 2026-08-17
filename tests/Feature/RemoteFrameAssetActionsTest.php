<?php

use App\Enums\ArAssetStatus;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\ArAsset;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $category = ProductCategory::factory()->create([
        'name' => 'AR actions test category '.fake()->uuid(),
    ]);
    $this->product = Product::factory()->create([
        'category_id' => $category->id,
        'product_type' => 'frame',
    ]);
    $this->variant = ProductVariant::factory()->for($this->product)->create();
});

test('staff sees only the applicable first step of the controlled 3D asset workflow', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->assertActionVisible(TestAction::make('uploadArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('submitArAssetForReview')->table($this->variant))
        ->assertActionHidden(TestAction::make('approveArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('publishArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('disableArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('rollbackArAsset')->table($this->variant));
});

test('the 3D asset picker exposes the GLB extension to the file chooser', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('uploadArAsset', $this->variant)
        ->assertMountedActionModalSeeHtml('accept=".glb,model/gltf-binary,application/octet-stream"');
});

test('staff can identify the current published version and inspect its history', function () {
    $staff = User::factory()->staff()->create();
    $contents = 'published model';

    $asset = ArAsset::factory()->published()->create([
        'product_variant_id' => $this->variant->id,
        'version' => 2,
        'published_path' => 'variants/'.$this->variant->id.'/v2/model.glb',
        'url' => 'https://cdn.example.com/ar/variants/'.$this->variant->id.'/v2/model.glb',
        'byte_size' => strlen($contents),
        'sha256' => hash('sha256', $contents),
        'published_by' => $staff->id,
        'published_at' => now(),
    ]);
    $this->variant->update(['published_ar_asset_id' => $asset->id]);

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->assertSee('v2')
        ->assertActionVisible(TestAction::make('viewArAssetHistory')->table($this->variant))
        ->mountTableAction('viewArAssetHistory', $this->variant)
        ->assertMountedActionModalSee([
            'Current patient model',
            'Version 2 is active for this variant.',
            'Open published asset',
        ]);
});

test('the staff workflow exposes review and publication actions for the matching asset status', function () {
    $uploader = User::factory()->staff()->create();
    $reviewer = User::factory()->staff()->create();

    ArAsset::factory()->create([
        'product_variant_id' => $this->variant->id,
        'status' => ArAssetStatus::Validated,
        'uploaded_by' => $uploader->id,
    ]);

    $this->actingAs($uploader);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->assertActionVisible(TestAction::make('uploadArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('submitArAssetForReview')->table($this->variant))
        ->assertActionHidden(TestAction::make('approveArAsset')->table($this->variant));

    $this->actingAs($reviewer);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->assertActionVisible(TestAction::make('approveArAsset')->table($this->variant));

    $asset = ArAsset::query()->where('product_variant_id', $this->variant->id)->firstOrFail();
    $asset->update(['status' => ArAssetStatus::Approved]);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->assertActionVisible(TestAction::make('publishArAsset')->table($this->variant));
});

test('the publish table action publishes an approved asset after confirmation', function () {
    $reviewer = User::factory()->staff()->create();
    $contents = 'valid glb contents';

    Storage::fake('ar_quarantine');
    Storage::fake('ar_published');
    config([
        'ar.assets.quarantine_disk' => 'ar_quarantine',
        'ar.assets.published_disk' => 'ar_published',
        'ar.assets.base_url' => 'https://cdn.example.com',
    ]);

    $asset = ArAsset::factory()->create([
        'product_variant_id' => $this->variant->id,
        'status' => ArAssetStatus::Approved,
        'quarantine_path' => 'quarantine/model.glb',
        'byte_size' => strlen($contents),
        'sha256' => hash('sha256', $contents),
    ]);
    Storage::disk('ar_quarantine')->put($asset->quarantine_path, $contents);

    $this->actingAs($reviewer);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->callAction(TestAction::make('publishArAsset')->table($this->variant))
        ->assertNotified('3D model published to the patient catalog')
        ->assertActionHidden(TestAction::make('publishArAsset')->table($this->variant));

    $published = $asset->fresh();

    expect($published->status)->toBe(ArAssetStatus::Published)
        ->and($this->variant->fresh()->published_ar_asset_id)->toBe($asset->id)
        ->and(Storage::disk('ar_published')->exists($published->published_path))->toBeTrue();
});

test('the publish table action reports a missing HTTPS asset base URL', function () {
    $reviewer = User::factory()->staff()->create();
    $contents = 'valid glb contents';

    Storage::fake('ar_quarantine');
    Storage::fake('ar_published');
    config([
        'ar.assets.quarantine_disk' => 'ar_quarantine',
        'ar.assets.published_disk' => 'ar_published',
        'ar.assets.base_url' => null,
    ]);

    $asset = ArAsset::factory()->create([
        'product_variant_id' => $this->variant->id,
        'status' => ArAssetStatus::Approved,
        'quarantine_path' => 'quarantine/model.glb',
        'byte_size' => strlen($contents),
        'sha256' => hash('sha256', $contents),
    ]);
    Storage::disk('ar_quarantine')->put($asset->quarantine_path, $contents);

    $this->actingAs($reviewer);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->callAction(TestAction::make('publishArAsset')->table($this->variant))
        ->assertNotified('3D asset was not published')
        ->assertNotNotified('3D model published to the patient catalog');

    expect($asset->fresh()->status)->toBe(ArAssetStatus::Approved)
        ->and($this->variant->fresh()->published_ar_asset_id)->toBeNull();
});

test('rollback restores the explicitly selected previous version', function () {
    $staff = User::factory()->staff()->create();
    $contents = [
        1 => 'version one',
        2 => 'version two',
        3 => 'version three',
    ];

    Storage::fake('ar_published');
    config(['ar.assets.published_disk' => 'ar_published']);

    $assets = collect($contents)->mapWithKeys(function (string $content, int $version): array {
        $path = 'variants/'.$this->variant->id.'/v'.$version.'/model.glb';
        $asset = ArAsset::factory()->published()->create([
            'product_variant_id' => $this->variant->id,
            'version' => $version,
            'status' => $version === 3 ? ArAssetStatus::Published : ArAssetStatus::Superseded,
            'published_path' => $path,
            'url' => 'https://cdn.example.com/ar/'.$path,
            'byte_size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'published_at' => now()->subDays(4 - $version),
        ]);
        Storage::disk('ar_published')->put($path, $content);

        return [$version => $asset];
    });
    $this->variant->update(['published_ar_asset_id' => $assets[3]->id]);

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->assertSee('v3')
        ->assertActionVisible(TestAction::make('rollbackArAsset')->table($this->variant))
        ->mountTableAction('rollbackArAsset', $this->variant)
        ->assertMountedActionModalSee([
            'Version to restore',
            'v1 · Superseded',
            'v2 · Superseded',
            'Restore selected version',
        ])
        ->fillForm(['target_asset_id' => (string) $assets[1]->id])
        ->callMountedTableAction()
        ->assertNotified('Selected 3D model version restored');

    expect($this->variant->fresh()->published_ar_asset_id)->toBe($assets[1]->id)
        ->and($assets[1]->fresh()->status)->toBe(ArAssetStatus::Published)
        ->and($assets[3]->fresh()->status)->toBe(ArAssetStatus::Superseded);
});

test('patients cannot see or invoke the 3D asset workflow actions', function () {
    $patient = User::factory()->patient()->create();

    $this->actingAs($patient);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->assertActionHidden(TestAction::make('uploadArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('submitArAssetForReview')->table($this->variant))
        ->assertActionHidden(TestAction::make('approveArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('publishArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('disableArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('rollbackArAsset')->table($this->variant));
});

test('inactive staff cannot see the 3D asset workflow actions', function () {
    $staff = User::factory()->staff()->create(['is_active' => false]);

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->assertActionHidden(TestAction::make('uploadArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('submitArAssetForReview')->table($this->variant))
        ->assertActionHidden(TestAction::make('approveArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('publishArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('disableArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('rollbackArAsset')->table($this->variant));
});
