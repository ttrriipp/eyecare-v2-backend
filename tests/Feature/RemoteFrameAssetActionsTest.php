<?php

use App\Enums\ArAssetStatus;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\ArAsset;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeGlbForActionsTest(): string
{
    $json = json_encode([
        'asset' => ['version' => '2.0'],
    ], JSON_THROW_ON_ERROR);
    $json .= str_repeat(' ', (4 - (strlen($json) % 4)) % 4);
    $chunk = pack('V2', strlen($json), 0x4E4F534A).$json;

    return pack('V3', 0x46546C67, 2, 12 + strlen($chunk)).$chunk;
}

function frameCalibrationForActionsTest(): array
{
    return [
        'frame_width_mm' => 123.0,
        'outer_frame_height_mm' => 48.0,
        'lens_width_mm' => 50.0,
        'lens_height_mm' => 45.0,
        'bridge_width_mm' => 20.0,
        'temple_length_mm' => 140.0,
        'scale_x' => 0.123,
        'scale_y' => 0.144565,
        'scale_z' => 0.123,
        'anchor_x' => 0.0,
        'anchor_y' => 0.0,
        'anchor_z' => 0.0,
        'rotation_x' => 0.0,
        'rotation_y' => 0.0,
        'rotation_z' => 0.0,
    ];
}

beforeEach(function (): void {
    Storage::fake('ar_quarantine');
    Storage::fake('ar_published');
    config([
        'ar.assets.quarantine_disk' => 'ar_quarantine',
        'ar.assets.published_disk' => 'ar_published',
        'ar.assets.base_url' => 'https://cdn.example.com',
    ]);

    $category = ProductCategory::factory()->create([
        'name' => 'AR actions test category '.fake()->uuid(),
    ]);
    $this->product = Product::factory()->create([
        'category_id' => $category->id,
        'product_type' => 'frame',
    ]);
    $this->variant = ProductVariant::factory()->for($this->product)->create();
});

test('staff sees one state-aware 3D model management action', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->assertActionVisible(TestAction::make('manageArAsset')->table($this->variant))
        ->assertActionDoesNotExist(TestAction::make('uploadArAsset')->table($this->variant))
        ->assertActionDoesNotExist(TestAction::make('submitArAssetForReview')->table($this->variant))
        ->assertActionDoesNotExist(TestAction::make('approveArAsset')->table($this->variant))
        ->assertActionDoesNotExist(TestAction::make('publishArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('disableArAsset')->table($this->variant))
        ->assertActionHidden(TestAction::make('rollbackArAsset')->table($this->variant));
});

test('the management modal explains the upload, calibration, and attestation steps', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('manageArAsset', $this->variant)
        ->assertMountedActionModalSeeHtml('accept=".glb,model/gltf-binary,application/octet-stream"')
        ->assertMountedActionModalSee([
            'Upload and publish 3D model',
            'Physical dimensions and placement',
            'Measured rendered width (mm)',
            'I compared this GLB with the physical frame and confirm that it represents this catalog variant, including its silhouette, bridge, material, color, and proportions.',
            'Validate & publish',
        ])
        ->assertMountedActionModalDontSee('GLB only, maximum 10 MiB.')
        ->assertMountedActionModalDontSee('Use the physical frame as the source of truth')
        ->assertMountedActionModalDontSee('Selecting the preset is explicit');
});

test('the first modal pre-fills available variant measurements', function () {
    $staff = User::factory()->staff()->create();
    $this->variant->update([
        'attributes' => [
            'lens_width' => 52,
            'lens_height' => 44,
            'bridge' => 18,
            'temple' => 140,
        ],
    ]);

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('manageArAsset', $this->variant)
        ->assertActionDataSet([
            'lens_width_mm' => 52,
            'lens_height_mm' => 44,
            'bridge_width_mm' => 18,
            'temple_length_mm' => 140,
        ]);
});

test('one operator can upload, calibrate, attest, approve, and publish from the management modal', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->callAction(TestAction::make('manageArAsset')->table($this->variant), [
            'file' => UploadedFile::fake()->createWithContent(
                'round-frame.glb',
                makeGlbForActionsTest(),
                'model/gltf-binary',
            ),
            ...frameCalibrationForActionsTest(),
            'measured_rendered_width_mm' => 61.5,
            'physical_match_confirmed' => true,
        ])
        ->assertNotified('3D model published to the patient catalog');

    $asset = ArAsset::query()->where('product_variant_id', $this->variant->id)->firstOrFail();

    expect($asset->status)->toBe(ArAssetStatus::Published)
        ->and($asset->uploaded_by)->toBe($staff->id)
        ->and($asset->approved_by)->toBe($staff->id)
        ->and($asset->published_by)->toBe($staff->id)
        ->and($this->variant->fresh()->published_ar_asset_id)->toBe($asset->id)
        ->and(Storage::disk('ar_published')->exists($asset->published_path))->toBeTrue();

    expect($asset->calibration)->toMatchArray([
        'frame_width_mm' => 123.0,
        'scale' => ['x' => 0.246, 'y' => 0.28913, 'z' => 0.246],
    ]);

    $approvalLog = AuditLog::query()
        ->where('subject_id', $asset->id)
        ->where('action', 'ar_asset.approved')
        ->sole();

    expect($approvalLog->metadata)->toMatchArray([
        'approval_mode' => 'coordinated_self_approval',
        'separation_of_duties_bypassed' => true,
    ]);
});

test('the management modal rejects a non-boolean attestation payload', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->callAction(TestAction::make('manageArAsset')->table($this->variant), [
            'file' => UploadedFile::fake()->createWithContent(
                'round-frame.glb',
                makeGlbForActionsTest(),
                'model/gltf-binary',
            ),
            ...frameCalibrationForActionsTest(),
            'physical_match_confirmed' => 1,
        ])
        ->assertNotified('3D model was not published');

    expect(ArAsset::query()->where('product_variant_id', $this->variant->id)->exists())->toBeFalse();
});

test('the modal reports a safe publication preflight failure', function () {
    $staff = User::factory()->staff()->create();
    config(['ar.assets.base_url' => null]);
    $this->variant->update([
        'name' => 'Round Black',
        'sku' => 'RB-001',
    ]);

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->callAction(TestAction::make('manageArAsset')->table($this->variant), [
            'file' => UploadedFile::fake()->createWithContent(
                'round-frame.glb',
                makeGlbForActionsTest(),
                'model/gltf-binary',
            ),
            ...frameCalibrationForActionsTest(),
            'physical_match_confirmed' => true,
        ])
        ->assertNotified(
            Notification::make()
                ->danger()
                ->title('3D model was not published')
                ->body('Variant "Round Black (SKU: RB-001)": A public HTTPS AR asset base URL is not configured.'),
        );

    expect(ArAsset::query()->where('product_variant_id', $this->variant->id)->exists())->toBeFalse();
});

test('the modal resumes an approved candidate without requesting a second upload', function () {
    $staff = User::factory()->staff()->create();
    $contents = makeGlbForActionsTest();

    $asset = ArAsset::factory()->create([
        'product_variant_id' => $this->variant->id,
        'status' => ArAssetStatus::Approved,
        'uploaded_by' => $staff->id,
        'quarantine_path' => 'quarantine/model.glb',
        'byte_size' => strlen($contents),
        'sha256' => hash('sha256', $contents),
        'calibration' => [
            'frame_width_mm' => 123,
            'outer_frame_height_mm' => 48,
            'lens_width_mm' => 50,
            'lens_height_mm' => 45,
            'bridge_width_mm' => 20,
            'temple_length_mm' => 140,
            'scale' => ['x' => 0.123, 'y' => 0.144565, 'z' => 0.123],
            'anchor' => ['x' => 0, 'y' => 0, 'z' => 0],
            'rotation_degrees' => ['x' => 0, 'y' => 0, 'z' => 0],
        ],
    ]);
    Storage::disk('ar_quarantine')->put($asset->quarantine_path, $contents);

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('manageArAsset', $this->variant)
        ->assertMountedActionModalSee([
            'Finish publishing approved 3D model',
            'Validate & publish',
        ])
        ->assertMountedActionModalSeeHtml('disabled')
        ->fillForm(['physical_match_confirmed' => true])
        ->callMountedTableAction()
        ->assertNotified('3D model published to the patient catalog');

    expect($asset->fresh()->status)->toBe(ArAssetStatus::Published);
});

test('the modal labels a validated candidate for approval and keeps calibration read-only', function () {
    $staff = User::factory()->staff()->create();

    ArAsset::factory()->create([
        'product_variant_id' => $this->variant->id,
        'status' => ArAssetStatus::Validated,
        'uploaded_by' => $staff->id,
        'calibration' => [
            'frame_width_mm' => 123,
            'outer_frame_height_mm' => 48,
            'lens_width_mm' => 50,
            'lens_height_mm' => 45,
            'bridge_width_mm' => 20,
            'temple_length_mm' => 140,
            'scale' => ['x' => 0.123, 'y' => 0.144565, 'z' => 0.123],
            'anchor' => ['x' => 0, 'y' => 0, 'z' => 0],
            'rotation_degrees' => ['x' => 0, 'y' => 0, 'z' => 0],
        ],
    ]);

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('manageArAsset', $this->variant)
        ->assertMountedActionModalSee([
            'Approve and publish 3D model',
            'The model passed file validation.',
            'Validate & publish',
        ])
        ->assertMountedActionModalDontSee('Measured rendered width (mm)')
        ->assertMountedActionModalSeeHtml('disabled');
});

test('the modal blocks a validated candidate with missing persisted calibration', function () {
    $staff = User::factory()->staff()->create();

    ArAsset::factory()->create([
        'product_variant_id' => $this->variant->id,
        'status' => ArAssetStatus::Validated,
        'uploaded_by' => $staff->id,
        'calibration' => [],
    ]);

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('manageArAsset', $this->variant)
        ->assertMountedActionModalSee([
            'Resolve invalid 3D model calibration',
            'This validated candidate has no usable saved calibration.',
            'Resolve this candidate through the administrator workflow',
        ])
        ->assertMountedActionModalDontSee('Validate & publish');
});

test('the modal blocks a validated candidate with unusable persisted calibration', function () {
    $staff = User::factory()->staff()->create();

    ArAsset::factory()->create([
        'product_variant_id' => $this->variant->id,
        'status' => ArAssetStatus::Validated,
        'uploaded_by' => $staff->id,
        'calibration' => [
            'frame_width_mm' => 123,
            'outer_frame_height_mm' => 48,
            'lens_width_mm' => 50,
            'lens_height_mm' => 45,
            'bridge_width_mm' => 20,
            'temple_length_mm' => 140,
            'scale' => ['x' => 0, 'y' => 0.144565, 'z' => 0.123],
            'anchor' => ['x' => 0, 'y' => 0, 'z' => 0],
            'rotation_degrees' => ['x' => 0, 'y' => 0, 'z' => 0],
        ],
    ]);

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('manageArAsset', $this->variant)
        ->assertMountedActionModalSee([
            'Resolve invalid 3D model calibration',
            'This validated candidate has no usable saved calibration.',
        ])
        ->assertMountedActionModalDontSee('Validate & publish');
});

test('the modal blocks ambiguous pending candidates instead of choosing one', function () {
    $staff = User::factory()->staff()->create();

    ArAsset::factory()->count(2)->sequence(
        ['version' => 1, 'status' => ArAssetStatus::Quarantined],
        ['version' => 2, 'status' => ArAssetStatus::Approved],
    )->create([
        'product_variant_id' => $this->variant->id,
        'uploaded_by' => $staff->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('manageArAsset', $this->variant)
        ->assertMountedActionModalSee([
            'Resolve pending 3D models',
            'Multiple pending 3D model candidates were found',
            'No candidate was selected automatically',
        ])
        ->assertMountedActionModalSeeHtml('Publication blocked');
});

test('patients and inactive staff cannot see the 3D model management action', function () {
    foreach ([
        User::factory()->patient()->create(),
        User::factory()->staff()->create(['is_active' => false]),
        User::factory()->optometrist()->create(),
    ] as $user) {
        $this->actingAs($user);

        Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $this->product,
            'pageClass' => EditProduct::class,
        ])
            ->assertActionHidden(TestAction::make('manageArAsset')->table($this->variant))
            ->assertActionHidden(TestAction::make('disableArAsset')->table($this->variant))
            ->assertActionHidden(TestAction::make('rollbackArAsset')->table($this->variant));
    }

    auth()->logout();

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $this->product,
        'pageClass' => EditProduct::class,
    ])
        ->assertActionHidden(TestAction::make('manageArAsset')->table($this->variant));
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

test('rollback restores the explicitly selected previous version', function () {
    $staff = User::factory()->staff()->create();
    $contents = [
        1 => 'version one',
        2 => 'version two',
        3 => 'version three',
    ];

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
