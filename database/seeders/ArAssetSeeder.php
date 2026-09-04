<?php

namespace Database\Seeders;

use App\Actions\ArAssets\DiscardArAsset;
use App\Actions\ArAssets\PublishArAssetCandidate;
use App\Enums\ArAssetStatus;
use App\Models\ArAsset;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ArAssets\ArCalibration;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ArAssetSeeder extends Seeder
{
    private const ACTOR_EMAIL = 'staff@eyecare.test';

    private const SOURCE_DIRECTORY = 'seeders/data/clinic-ar-assets';

    private const TORTOISE_RENDERER_SCALE = 0.185;

    /**
     * @var list<array{sku: string, file: string, calibration: array<string, mixed>}>
     */
    private const ASSETS = [
        [
            'sku' => 'FRM-ANTHOS-MB1399A-C4',
            'file' => 'frame-002-tortoise-rectangle-v2.glb',
            'calibration' => [
                'frame_width_mm' => 138.0,
                'outer_frame_height_mm' => 45.0,
                'lens_width_mm' => 54.0,
                'lens_height_mm' => 40.0,
                'bridge_width_mm' => 18.0,
                'temple_length_mm' => 145.0,
                'scale' => [
                    'x' => self::TORTOISE_RENDERER_SCALE,
                    'y' => self::TORTOISE_RENDERER_SCALE,
                    'z' => self::TORTOISE_RENDERER_SCALE,
                ],
                'anchor' => ['x' => -0.0075, 'y' => 0.026, 'z' => 0.0],
                'rotation_degrees' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            ],
        ],
    ];

    public function run(): void
    {
        $actor = User::query()
            ->where('email', self::ACTOR_EMAIL)
            ->firstOrFail();

        foreach (self::ASSETS as $definition) {
            $this->seedAsset($definition, $actor);
        }
    }

    /**
     * @param  array{sku: string, file: string, calibration: array<string, mixed>}  $definition
     */
    private function seedAsset(array $definition, User $actor): void
    {
        $sourcePath = database_path(self::SOURCE_DIRECTORY.'/'.$definition['file']);

        if (! is_file($sourcePath)) {
            throw new RuntimeException(sprintf('The seeded AR model is missing: %s', $sourcePath));
        }

        $sourceHash = hash_file('sha256', $sourcePath);

        if (! is_string($sourceHash)) {
            throw new RuntimeException(sprintf('The seeded AR model could not be hashed: %s', $sourcePath));
        }

        $calibration = app(ArCalibration::class)->normalize($definition['calibration']);
        $variant = ProductVariant::query()
            ->with('publishedArAsset')
            ->where('sku', $definition['sku'])
            ->firstOrFail();

        if ($this->publishedAssetMatches($variant->publishedArAsset, $sourceHash, $calibration)) {
            return;
        }

        $candidate = $this->actionableCandidate($variant);

        if ($candidate !== null) {
            if ($candidate->sha256 !== $sourceHash) {
                throw new RuntimeException(sprintf(
                    'Variant %s has a pending AR model with a different source file. Resolve it before seeding %s.',
                    $definition['sku'],
                    $definition['file'],
                ));
            }

            if ($candidate->status !== ArAssetStatus::Quarantined
                && ! $this->calibrationMatches($candidate->calibration, $calibration)) {
                app(DiscardArAsset::class)->handle($candidate, $actor);
                $candidate = null;
            }
        }

        app(PublishArAssetCandidate::class)->handle(
            variant: $variant,
            file: $candidate === null
                ? new UploadedFile($sourcePath, basename($sourcePath), 'model/gltf-binary', UPLOAD_ERR_OK, true)
                : null,
            calibration: $calibration,
            physicalMatchConfirmed: true,
            actor: $actor,
        );
    }

    private function publishedAssetMatches(?ArAsset $asset, string $sourceHash, array $calibration): bool
    {
        return $asset !== null
            && $asset->isPatientReady()
            && $asset->sha256 === $sourceHash
            && $asset->url === $this->expectedUrl($asset)
            && $this->calibrationMatches($asset->calibration, $calibration);
    }

    /**
     * @return Collection<int, ArAsset>
     */
    private function actionableAssets(ProductVariant $variant): Collection
    {
        return $variant->arAssets()
            ->whereIn('status', [
                ArAssetStatus::Quarantined->value,
                ArAssetStatus::Validated->value,
                ArAssetStatus::Approved->value,
            ])
            ->latest('version')
            ->get();
    }

    private function actionableCandidate(ProductVariant $variant): ?ArAsset
    {
        $candidates = $this->actionableAssets($variant);

        if ($candidates->count() > 1) {
            throw new RuntimeException(sprintf(
                'Variant %s has multiple pending AR models. Resolve them before seeding.',
                $variant->sku,
            ));
        }

        return $candidates->first();
    }

    /**
     * @param  array<string, mixed>|null  $candidateCalibration
     * @param  array<string, mixed>  $expectedCalibration
     */
    private function calibrationMatches(?array $candidateCalibration, array $expectedCalibration): bool
    {
        if ($candidateCalibration === null) {
            return false;
        }

        try {
            return app(ArCalibration::class)->normalize($candidateCalibration) == $expectedCalibration;
        } catch (ValidationException) {
            return false;
        }
    }

    private function expectedUrl(ArAsset $asset): string
    {
        return sprintf(
            '%s/%s/%d/v%d/model.glb',
            rtrim((string) config('ar.assets.base_url'), '/'),
            trim((string) config('ar.assets.public_prefix', 'ar/variants'), '/'),
            $asset->product_variant_id,
            $asset->version,
        );
    }
}
