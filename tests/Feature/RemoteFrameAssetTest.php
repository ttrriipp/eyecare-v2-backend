<?php

use App\Actions\ArAssets\ApproveArAsset;
use App\Actions\ArAssets\DisableArAsset;
use App\Actions\ArAssets\DiscardArAsset;
use App\Actions\ArAssets\PublishArAsset;
use App\Actions\ArAssets\PublishArAssetCandidate;
use App\Actions\ArAssets\RollbackArAsset;
use App\Actions\ArAssets\SubmitArAssetForReview;
use App\Actions\ArAssets\UploadArAsset;
use App\Actions\Audit\CreateAuditLog;
use App\Enums\ArAssetStatus;
use App\Models\ArAsset;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

function makeGlbForTest(array $overrides = [], string $binary = ''): string
{
    $document = array_replace_recursive([
        'asset' => ['version' => '2.0'],
        'buffers' => $binary === '' ? [] : [['byteLength' => strlen($binary)]],
    ], $overrides);
    $json = json_encode($document, JSON_THROW_ON_ERROR);
    $json .= str_repeat(' ', (4 - (strlen($json) % 4)) % 4);

    $chunks = pack('V2', strlen($json), 0x4E4F534A).$json;

    if ($binary !== '') {
        $binary .= str_repeat("\0", (4 - (strlen($binary) % 4)) % 4);
        $chunks .= pack('V2', strlen($binary), 0x004E4942).$binary;
    }

    return pack('V3', 0x46546C67, 2, 12 + strlen($chunks)).$chunks;
}

function makeRawGlbForTest(string $json, string $binary = ''): string
{
    $json .= str_repeat(' ', (4 - (strlen($json) % 4)) % 4);
    $chunks = pack('V2', strlen($json), 0x4E4F534A).$json;

    if ($binary !== '') {
        $binary .= str_repeat("\0", (4 - (strlen($binary) % 4)) % 4);
        $chunks .= pack('V2', strlen($binary), 0x004E4942).$binary;
    }

    return pack('V3', 0x46546C67, 2, 12 + strlen($chunks)).$chunks;
}

function frameCalibrationForTest(): array
{
    return [
        'frame_width_mm' => 123.0,
        'outer_frame_height_mm' => 48.0,
        'lens_width_mm' => 50.0,
        'lens_height_mm' => 45.0,
        'bridge_width_mm' => 20.0,
        'temple_length_mm' => 140.0,
        'scale' => ['x' => 0.123, 'y' => 0.144565, 'z' => 0.123],
        'anchor' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        'rotation_degrees' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
    ];
}

function submitArAssetForReviewForTest(ArAsset $asset, User $actor): ArAsset
{
    return app(SubmitArAssetForReview::class)->handle(
        asset: $asset,
        calibration: frameCalibrationForTest(),
        actor: $actor,
    );
}

beforeEach(function (): void {
    Storage::fake('ar_quarantine');
    Storage::fake('ar_published');
    config([
        'ar.assets.quarantine_disk' => 'ar_quarantine',
        'ar.assets.published_disk' => 'ar_published',
        'ar.assets.base_url' => 'https://cdn.example.com',
    ]);

    $this->brand = Brand::factory()->create();
    $this->category = ProductCategory::factory()->create([
        'name' => 'AR test category '.fake()->uuid(),
    ]);
    $this->staff = User::factory()->staff()->create();
    $this->reviewer = User::factory()->staff()->create();
    $this->patient = User::factory()->patient()->create();
    $this->frame = Product::factory()->create([
        'brand_id' => $this->brand->id,
        'category_id' => $this->category->id,
        'product_type' => 'frame',
    ]);
    $this->variant = ProductVariant::factory()->for($this->frame)->create([
        'images' => ['variants/round-black.jpg'],
    ]);
});

test('a received GLB is submitted for review and published without changing the patient contract', function () {
    $asset = app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent(
            'round-black.glb',
            makeGlbForTest(),
            'model/gltf-binary',
        ),
        calibration: frameCalibrationForTest(),
        actor: $this->staff,
    );

    expect($asset->status->value)->toBe('quarantined')
        ->and($asset->version)->toBe(1)
        ->and($asset->quarantine_path)->not->toContain('round-black')
        ->and(Storage::disk('ar_quarantine')->exists($asset->quarantine_path))->toBeTrue();

    $submitted = submitArAssetForReviewForTest($asset, $this->staff);
    expect($submitted->status->value)->toBe('validated');

    app(ApproveArAsset::class)->handle($submitted, $this->reviewer);
    $published = app(PublishArAsset::class)->handle($submitted->fresh(), $this->reviewer);

    $response = $this->actingAs($this->patient)
        ->getJson("/api/v1/frames/{$this->frame->id}")
        ->assertOk();

    $response->assertJsonPath('data.variants.0.ar.status', 'ready')
        ->assertJsonPath('data.variants.0.ar.asset.url', $published->url)
        ->assertJsonPath('data.variants.0.ar.asset.format', 'glb')
        ->assertJsonPath('data.variants.0.ar.asset.version', 1)
        ->assertJsonPath('data.variants.0.ar.asset.byte_size', strlen(makeGlbForTest()))
        ->assertJsonPath('data.variants.0.ar.calibration.frame_width_mm', 123)
        ->assertJsonPath('data.variants.0.ar.calibration.scale.y', 0.144565);

    expect($response->json('data.variants.0.ar.asset.sha256'))
        ->toBe(hash('sha256', makeGlbForTest()));
});

test('the uploader cannot approve their own submitted asset', function () {
    $asset = app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: [],
        actor: $this->staff,
    );
    $submitted = submitArAssetForReviewForTest($asset, $this->staff);

    expect(fn () => app(ApproveArAsset::class)->handle($submitted, $this->staff))
        ->toThrow(ValidationException::class);

    expect($submitted->fresh()->status->value)->toBe('validated');
});

test('coordinated self-approval records the explicit policy exception', function () {
    $asset = app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: [],
        actor: $this->staff,
    );
    $submitted = submitArAssetForReviewForTest($asset, $this->staff);

    $approved = app(ApproveArAsset::class)->handle(
        asset: $submitted,
        actor: $this->staff,
        allowUploaderSelfApproval: true,
    );

    $approvalLog = AuditLog::query()
        ->where('subject_id', $asset->id)
        ->where('action', 'ar_asset.approved')
        ->sole();

    expect($approved->status->value)->toBe('approved')
        ->and($approved->approved_by)->toBe($this->staff->id)
        ->and($approvalLog->metadata)->toMatchArray([
            'approval_mode' => 'coordinated_self_approval',
            'separation_of_duties_bypassed' => true,
        ]);
});

test('invalid review calibration leaves the candidate quarantined and correctable', function () {
    $asset = app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: [],
        actor: $this->staff,
    );

    expect(fn () => app(SubmitArAssetForReview::class)->handle(
        asset: $asset,
        calibration: ['frame_width_mm' => 0],
        actor: $this->staff,
    ))->toThrow(ValidationException::class);

    $asset = $asset->fresh();

    expect($asset->status->value)->toBe('quarantined')
        ->and($asset->validation_error)->toBeNull()
        ->and(AuditLog::query()
            ->where('subject_id', $asset->id)
            ->where('action', 'ar_asset.rejected')
            ->count())->toBe(0);
});

test('a failed upload transaction removes the quarantine object', function () {
    mock(CreateAuditLog::class)
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('audit storage unavailable'));

    expect(fn () => app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: [],
        actor: $this->staff,
    ))->toThrow(RuntimeException::class, 'audit storage unavailable');

    expect(ArAsset::query()->count())->toBe(0)
        ->and(Storage::disk('ar_quarantine')->allFiles())->toBeEmpty();
});

test('one operator can publish a first asset through the coordinator', function () {
    $published = app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        physicalMatchConfirmed: true,
        actor: $this->staff,
    );

    $approvalLog = AuditLog::query()
        ->where('subject_id', $published->id)
        ->where('action', 'ar_asset.approved')
        ->sole();

    expect($published->status->value)->toBe('published')
        ->and($published->uploaded_by)->toBe($this->staff->id)
        ->and($published->approved_by)->toBe($this->staff->id)
        ->and($published->published_by)->toBe($this->staff->id)
        ->and($approvalLog->metadata)->toMatchArray([
            'approval_mode' => 'coordinated_self_approval',
            'separation_of_duties_bypassed' => true,
        ])
        ->and($this->variant->fresh()->published_ar_asset_id)->toBe($published->id);

    $this->actingAs($this->patient)
        ->getJson("/api/v1/frames/{$this->frame->id}")
        ->assertOk()
        ->assertJsonPath('data.variants.0.ar.status', 'ready')
        ->assertJsonPath('data.variants.0.ar.asset.version', 1)
        ->assertJsonPath('data.variants.0.images', ['variants/round-black.jpg']);
});

test('the coordinator rejects missing attestation before creating a candidate', function () {
    expect(fn () => app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        physicalMatchConfirmed: false,
        actor: $this->staff,
    ))->toThrow(ValidationException::class);

    expect(ArAsset::query()->count())->toBe(0)
        ->and(Storage::disk('ar_quarantine')->allFiles())->toBeEmpty();
});

test('the coordinator does not coerce a tampered attestation into approval', function () {
    expect(fn () => app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        physicalMatchConfirmed: 'false',
        actor: $this->staff,
    ))->toThrow(ValidationException::class);

    expect(ArAsset::query()->count())->toBe(0)
        ->and(Storage::disk('ar_quarantine')->allFiles())->toBeEmpty();
});

test('the coordinator rejects a publication configuration failure before creating a candidate', function () {
    config(['ar.assets.base_url' => null]);

    expect(fn () => app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        physicalMatchConfirmed: true,
        actor: $this->staff,
    ))->toThrow(ValidationException::class);

    expect(ArAsset::query()->count())->toBe(0)
        ->and(Storage::disk('ar_quarantine')->allFiles())->toBeEmpty();
});

test('patients cannot publish through the coordinator', function () {
    expect(fn () => app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        physicalMatchConfirmed: true,
        actor: $this->patient,
    ))->toThrow(AuthorizationException::class);

    expect(ArAsset::query()->count())->toBe(0)
        ->and(Storage::disk('ar_quarantine')->allFiles())->toBeEmpty();
});

test('the coordinator requires an active frame variant', function () {
    $this->variant->update(['is_active' => false]);

    expect(fn () => app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        physicalMatchConfirmed: true,
        actor: $this->staff,
    ))->toThrow(ValidationException::class);

    expect(ArAsset::query()->count())->toBe(0)
        ->and(Storage::disk('ar_quarantine')->allFiles())->toBeEmpty();
});

test('the coordinator rejects invalid calibration before creating a candidate', function () {
    expect(fn () => app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: ['frame_width_mm' => 0],
        physicalMatchConfirmed: true,
        actor: $this->staff,
    ))->toThrow(ValidationException::class);

    expect(ArAsset::query()->count())->toBe(0)
        ->and(Storage::disk('ar_quarantine')->allFiles())->toBeEmpty();
});

test('the coordinator adjusts every scale axis from the measured rendered width', function () {
    $calibration = frameCalibrationForTest();
    $calibration['scale'] = ['x' => 0.123, 'y' => 0.144565, 'z' => 0.123];

    $published = app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: $calibration,
        measuredRenderedWidthMm: 61.5,
        physicalMatchConfirmed: true,
        actor: $this->staff,
    );

    expect($published->calibration)->toMatchArray([
        'frame_width_mm' => 123.0,
        'outer_frame_height_mm' => 48.0,
        'scale' => ['x' => 0.246, 'y' => 0.28913, 'z' => 0.246],
    ]);
});

test('an invalid measured rendered width is rejected before creating a candidate', function () {
    expect(fn () => app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        measuredRenderedWidthMm: 0,
        physicalMatchConfirmed: true,
        actor: $this->staff,
    ))->toThrow(ValidationException::class);

    expect(ArAsset::query()->count())->toBe(0)
        ->and(Storage::disk('ar_quarantine')->allFiles())->toBeEmpty();
});

test('the coordinator applies measured width when resuming a quarantined candidate', function () {
    $asset = app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        actor: $this->staff,
    );

    $published = app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: null,
        calibration: [],
        measuredRenderedWidthMm: 61.5,
        physicalMatchConfirmed: true,
        actor: $this->staff,
    );

    expect($published->id)->toBe($asset->id)
        ->and($published->calibration['frame_width_mm'])->toEqual(123.0)
        ->and($published->calibration['scale'])->toMatchArray([
            'x' => 0.246,
            'y' => 0.28913,
            'z' => 0.246,
        ]);
});

test('the coordinator keeps validated calibration locked against measured-width adjustment', function () {
    $asset = app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        actor: $this->staff,
    );
    $validated = submitArAssetForReviewForTest($asset, $this->staff);

    expect(fn () => app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: null,
        calibration: [],
        measuredRenderedWidthMm: 61.5,
        physicalMatchConfirmed: true,
        actor: $this->reviewer,
    ))->toThrow(ValidationException::class);

    expect($validated->fresh()->status->value)->toBe('validated')
        ->and($validated->fresh()->calibration['scale'])->toMatchArray([
            'x' => 0.123,
            'y' => 0.144565,
            'z' => 0.123,
        ]);
});

test('the coordinator resumes an approved candidate without another upload', function () {
    $asset = app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-black.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        actor: $this->staff,
    );
    $submitted = submitArAssetForReviewForTest($asset, $this->staff);
    $approved = app(ApproveArAsset::class)->handle($submitted, $this->reviewer);

    $published = app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: null,
        calibration: [],
        physicalMatchConfirmed: true,
        actor: $this->reviewer,
    );

    expect($published->id)->toBe($approved->id)
        ->and($published->status->value)->toBe('published')
        ->and(ArAsset::query()->where('product_variant_id', $this->variant->id)->count())->toBe(1);
});

test('a publication failure leaves the approved candidate retryable', function () {
    $first = app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-v1.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        physicalMatchConfirmed: true,
        actor: $this->staff,
    );
    $asset = app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('round-v2.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        actor: $this->staff,
    );
    $submitted = submitArAssetForReviewForTest($asset, $this->staff);
    $approved = app(ApproveArAsset::class)->handle($submitted, $this->reviewer);
    Storage::disk('ar_quarantine')->put($approved->quarantine_path, 'corrupted');

    expect(fn () => app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: null,
        calibration: [],
        physicalMatchConfirmed: true,
        actor: $this->reviewer,
    ))->toThrow(ValidationException::class);

    expect($approved->fresh()->status->value)->toBe('approved')
        ->and($this->variant->fresh()->published_ar_asset_id)->toBe($first->id);
});

test('the coordinator blocks multiple actionable candidates', function () {
    ArAsset::factory()->create([
        'product_variant_id' => $this->variant->id,
        'status' => 'quarantined',
        'version' => 1,
    ]);
    ArAsset::factory()->create([
        'product_variant_id' => $this->variant->id,
        'status' => 'approved',
        'version' => 2,
    ]);

    expect(fn () => app(PublishArAssetCandidate::class)->handle(
        variant: $this->variant,
        file: null,
        calibration: [],
        physicalMatchConfirmed: true,
        actor: $this->staff,
    ))->toThrow(ValidationException::class);
});

test('variants without a published asset expose a null ar field', function () {
    $response = $this->actingAs($this->patient)
        ->getJson("/api/v1/frames/{$this->frame->id}")
        ->assertOk();

    expect($response->json('data.variants.0.ar'))->toBeNull();
});

test('invalid GLB uploads are quarantined and rejected without patient details', function () {
    expect(fn () => app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent(
            'not-a-model.glb',
            'not a glb file',
            'model/gltf-binary',
        ),
        calibration: frameCalibrationForTest(),
        actor: $this->staff,
    ))->toThrow(ValidationException::class);

    $asset = ArAsset::query()->latest('id')->firstOrFail();

    expect($asset->status->value)->toBe('rejected')
        ->and($asset->validation_error)->not->toBeNull()
        ->and(Storage::disk('ar_published')->allFiles())->toBeEmpty();

    $this->actingAs($this->patient)
        ->getJson("/api/v1/frames/{$this->frame->id}")
        ->assertOk()
        ->assertJsonPath('data.variants.0.ar', null)
        ->assertJsonMissingPath('data.variants.0.ar.validation_error');
});

test('oversized GLB uploads are rejected before quarantine storage', function () {
    $glb = makeGlbForTest();
    config(['ar.assets.max_bytes' => strlen($glb) - 1]);

    expect(fn () => app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('too-large.glb', $glb, 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        actor: $this->staff,
    ))->toThrow(ValidationException::class);

    expect(ArAsset::query()->count())->toBe(0)
        ->and(Storage::disk('ar_quarantine')->allFiles())->toBeEmpty();
});

test('patients cannot upload GLB assets', function () {
    expect(fn () => app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('model.glb', makeGlbForTest(), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        actor: $this->patient,
    ))->toThrow(AuthorizationException::class);

    expect(ArAsset::query()->count())->toBe(0);
});

test('disabling a published asset removes only ar from the patient response', function () {
    $asset = app(UploadArAsset::class)->handle(
        $this->variant,
        UploadedFile::fake()->createWithContent('round.glb', makeGlbForTest(), 'model/gltf-binary'),
        frameCalibrationForTest(),
        $this->staff,
    );
    $submitted = submitArAssetForReviewForTest($asset, $this->staff);
    app(ApproveArAsset::class)->handle($submitted, $this->reviewer);
    app(PublishArAsset::class)->handle($submitted->fresh(), $this->reviewer);

    app(DisableArAsset::class)->handle($submitted->fresh(), $this->reviewer);

    $response = $this->actingAs($this->patient)
        ->getJson("/api/v1/frames/{$this->frame->id}")
        ->assertOk();

    expect($response->json('data.variants.0.ar'))->toBeNull()
        ->and($response->json('data.variants.0.images'))->toBe(['variants/round-black.jpg']);
});

test('discarding an unpublished asset removes its private file and records the action', function () {
    $asset = app(UploadArAsset::class)->handle(
        $this->variant,
        UploadedFile::fake()->createWithContent('round.glb', makeGlbForTest(), 'model/gltf-binary'),
        frameCalibrationForTest(),
        $this->staff,
    );

    expect(Storage::disk('ar_quarantine')->exists($asset->quarantine_path))->toBeTrue();

    $discarded = app(DiscardArAsset::class)->handle($asset, $this->staff);

    expect($discarded->status)->toBe(ArAssetStatus::Discarded)
        ->and(Storage::disk('ar_quarantine')->exists($asset->quarantine_path))->toBeFalse()
        ->and(AuditLog::query()
            ->where('subject_id', $asset->id)
            ->where('action', 'ar_asset.discarded')
            ->where('actor_id', $this->staff->id)
            ->exists())->toBeTrue();
});

test('discarding a published or historical asset is rejected', function (string $status) {
    $status = ArAssetStatus::from($status);

    $asset = ArAsset::factory()->create([
        'product_variant_id' => $this->variant->id,
        'status' => $status,
    ]);

    expect(fn () => app(DiscardArAsset::class)->handle($asset, $this->staff))
        ->toThrow(ValidationException::class);

    expect($asset->fresh()->status)->toBe($status);
})->with([
    'published',
    'superseded',
    'disabled',
]);

test('patients cannot discard an unpublished asset', function () {
    $asset = ArAsset::factory()->create([
        'product_variant_id' => $this->variant->id,
        'status' => ArAssetStatus::Quarantined,
    ]);

    expect(fn () => app(DiscardArAsset::class)->handle($asset, $this->patient))
        ->toThrow(AuthorizationException::class);

    expect($asset->fresh()->status)->toBe(ArAssetStatus::Quarantined);
});

test('discarding reports a private-file cleanup failure', function () {
    $asset = app(UploadArAsset::class)->handle(
        $this->variant,
        UploadedFile::fake()->createWithContent('round.glb', makeGlbForTest(), 'model/gltf-binary'),
        frameCalibrationForTest(),
        $this->staff,
    );
    $disk = mock(Filesystem::class);
    $disk->shouldReceive('exists')->once()->with($asset->quarantine_path)->andReturnTrue();
    $disk->shouldReceive('delete')->once()->with($asset->quarantine_path)->andReturnFalse();
    Storage::shouldReceive('disk')->once()->with('ar_quarantine')->andReturn($disk);
    Exceptions::fake();

    $discarded = app(DiscardArAsset::class)->handle($asset, $this->staff);

    Exceptions::assertReported(RuntimeException::class);
    expect($discarded->status)->toBe(ArAssetStatus::Discarded);
});

test('a corrupted published file is omitted from the patient response', function () {
    $asset = app(UploadArAsset::class)->handle(
        $this->variant,
        UploadedFile::fake()->createWithContent('round.glb', makeGlbForTest(), 'model/gltf-binary'),
        frameCalibrationForTest(),
        $this->staff,
    );
    $submitted = submitArAssetForReviewForTest($asset, $this->staff);
    app(ApproveArAsset::class)->handle($submitted, $this->reviewer);
    $published = app(PublishArAsset::class)->handle($submitted->fresh(), $this->reviewer);

    Storage::disk('ar_published')->put($published->published_path, 'corrupted');

    $this->actingAs($this->patient)
        ->getJson("/api/v1/frames/{$this->frame->id}")
        ->assertOk()
        ->assertJsonPath('data.variants.0.ar', null);
});

test('an expired published asset is omitted from the patient response', function () {
    $asset = app(UploadArAsset::class)->handle(
        $this->variant,
        UploadedFile::fake()->createWithContent('round.glb', makeGlbForTest(), 'model/gltf-binary'),
        frameCalibrationForTest(),
        $this->staff,
    );
    $submitted = submitArAssetForReviewForTest($asset, $this->staff);
    app(ApproveArAsset::class)->handle($submitted, $this->reviewer);
    $published = app(PublishArAsset::class)->handle($submitted->fresh(), $this->reviewer);
    $published->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($this->patient)
        ->getJson("/api/v1/frames/{$this->frame->id}")
        ->assertOk()
        ->assertJsonPath('data.variants.0.ar', null);
});

test('the frame list endpoint exposes the same ready ar contract as frame detail', function () {
    $asset = app(UploadArAsset::class)->handle(
        $this->variant,
        UploadedFile::fake()->createWithContent('round.glb', makeGlbForTest(), 'model/gltf-binary'),
        frameCalibrationForTest(),
        $this->staff,
    );
    $submitted = submitArAssetForReviewForTest($asset, $this->staff);
    app(ApproveArAsset::class)->handle($submitted, $this->reviewer);
    app(PublishArAsset::class)->handle($submitted->fresh(), $this->reviewer);

    $this->actingAs($this->patient)
        ->getJson('/api/v1/frames')
        ->assertOk()
        ->assertJsonPath('data.0.variants.0.ar.status', 'ready')
        ->assertJsonMissingPath('data.0.variants.0.ar.quarantine_path')
        ->assertJsonMissingPath('data.0.variants.0.ar.validation_error');
});

test('publishing a replacement switches versions only after approval and keeps the previous asset for rollback', function () {
    $first = app(UploadArAsset::class)->handle(
        $this->variant,
        UploadedFile::fake()->createWithContent('round-v1.glb', makeGlbForTest(), 'model/gltf-binary'),
        frameCalibrationForTest(),
        $this->staff,
    );
    $submittedFirst = submitArAssetForReviewForTest($first, $this->staff);
    app(ApproveArAsset::class)->handle($submittedFirst, $this->reviewer);
    $publishedFirst = app(PublishArAsset::class)->handle($submittedFirst->fresh(), $this->reviewer);

    $second = app(UploadArAsset::class)->handle(
        $this->variant,
        UploadedFile::fake()->createWithContent('round-v2.glb', makeGlbForTest(['asset' => ['generator' => 'v2']]), 'model/gltf-binary'),
        frameCalibrationForTest(),
        $this->staff,
    );

    expect($second->version)->toBe(2)
        ->and($this->variant->fresh()->published_ar_asset_id)->toBe($publishedFirst->id);

    $submittedSecond = submitArAssetForReviewForTest($second, $this->staff);
    app(ApproveArAsset::class)->handle($submittedSecond, $this->reviewer);
    $publishedSecond = app(PublishArAsset::class)->handle($submittedSecond->fresh(), $this->reviewer);

    expect($publishedSecond->version)->toBe(2)
        ->and($this->variant->fresh()->published_ar_asset_id)->toBe($publishedSecond->id)
        ->and($publishedFirst->fresh()->status->value)->toBe('superseded')
        ->and(Storage::disk('ar_published')->exists($publishedFirst->published_path))->toBeTrue();

    $this->actingAs($this->patient)
        ->getJson("/api/v1/frames/{$this->frame->id}")
        ->assertOk()
        ->assertJsonPath('data.variants.0.ar.asset.version', 2)
        ->assertJsonPath('data.variants.0.ar.asset.byte_size', strlen(Storage::disk('ar_published')->get($publishedSecond->published_path)));

    $rolledBack = app(RollbackArAsset::class)->handle($publishedFirst->fresh(), $this->reviewer);

    expect($rolledBack->status->value)->toBe('published')
        ->and($this->variant->fresh()->published_ar_asset_id)->toBe($publishedFirst->id)
        ->and($publishedSecond->fresh()->status->value)->toBe('superseded');
});

test('asset lifecycle actions write an auditable actor and action trail', function () {
    $asset = app(UploadArAsset::class)->handle(
        $this->variant,
        UploadedFile::fake()->createWithContent('round.glb', makeGlbForTest(), 'model/gltf-binary'),
        frameCalibrationForTest(),
        $this->staff,
    );
    $submitted = submitArAssetForReviewForTest($asset, $this->staff);
    app(ApproveArAsset::class)->handle($submitted, $this->reviewer);
    app(PublishArAsset::class)->handle($submitted->fresh(), $this->reviewer);
    app(DisableArAsset::class)->handle($submitted->fresh(), $this->reviewer);

    $actions = AuditLog::query()
        ->where('subject_type', (new ArAsset)->getMorphClass())
        ->where('subject_id', $asset->id)
        ->pluck('action')
        ->all();

    expect($actions)
        ->toContain('ar_asset.uploaded')
        ->toContain('ar_asset.validated')
        ->toContain('ar_asset.approved')
        ->toContain('ar_asset.published')
        ->toContain('ar_asset.disabled')
        ->and(AuditLog::query()->where('subject_id', $asset->id)->where('actor_id', $this->staff->id)->count())
        ->toBeGreaterThanOrEqual(2)
        ->and(AuditLog::query()->where('subject_id', $asset->id)->where('actor_id', $this->reviewer->id)->count())
        ->toBeGreaterThanOrEqual(3);
});

test('the validator rejects external resources, unsupported textures, malformed transforms, and excessive geometry', function (string $json, string $binary) {
    expect(fn () => app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('unsafe.glb', makeRawGlbForTest($json, $binary), 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        actor: $this->staff,
    ))->toThrow(ValidationException::class);
})->with(function (): array {
    $oversizedPng = "\x89PNG\r\n\x1a\n".str_repeat("\0", 8).pack('N2', 4096, 1);

    return [
        'external buffer' => [
            json_encode([
                'asset' => ['version' => '2.0'],
                'buffers' => [['byteLength' => 0, 'uri' => 'https://evil.example/model.bin']],
            ], JSON_THROW_ON_ERROR),
            '',
        ],
        'unsupported texture' => [
            json_encode([
                'asset' => ['version' => '2.0'],
                'buffers' => [['byteLength' => 24]],
                'bufferViews' => [['buffer' => 0, 'byteLength' => 24]],
                'images' => [['bufferView' => 0, 'mimeType' => 'image/webp']],
            ], JSON_THROW_ON_ERROR),
            $oversizedPng,
        ],
        'malformed transform' => [
            '{"asset":{"version":"2.0"},"nodes":[{"translation":[1e400,0,0]}]}',
            '',
        ],
        'malformed extension metadata' => [
            json_encode([
                'asset' => ['version' => '2.0'],
                'extensionsUsed' => [123],
            ], JSON_THROW_ON_ERROR),
            '',
        ],
    ];
});

test('the validator enforces the configured geometry triangle limit', function () {
    config(['ar.assets.max_triangles' => 1]);

    $glb = makeGlbForTest([
        'buffers' => [['byteLength' => 72]],
        'bufferViews' => [['buffer' => 0, 'byteLength' => 72]],
        'accessors' => [[
            'bufferView' => 0,
            'componentType' => 5126,
            'count' => 6,
            'type' => 'VEC3',
        ]],
        'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]]],
    ], str_repeat("\0", 72));

    expect(fn () => app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('too-many-triangles.glb', $glb, 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        actor: $this->staff,
    ))->toThrow(ValidationException::class);
});

test('the validator rejects overflowing buffer offsets', function () {
    $glb = makeGlbForTest([
        'buffers' => [['byteLength' => 0]],
        'bufferViews' => [[
            'buffer' => 0,
            'byteOffset' => PHP_INT_MAX,
            'byteLength' => 0,
        ]],
    ]);

    expect(fn () => app(UploadArAsset::class)->handle(
        variant: $this->variant,
        file: UploadedFile::fake()->createWithContent('overflow.glb', $glb, 'model/gltf-binary'),
        calibration: frameCalibrationForTest(),
        actor: $this->staff,
    ))->toThrow(ValidationException::class);
});
