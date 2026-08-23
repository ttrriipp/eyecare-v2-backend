<?php

namespace App\Actions\ArAssets;

use App\Enums\ArAssetStatus;
use App\Models\ArAsset;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ArAssets\ArAssetAuthorizer;
use App\Services\ArAssets\ArCalibration;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class PublishArAssetCandidate
{
    private const LOCK_SECONDS = 120;

    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly ArAssetAuthorizer $authorizer,
        private readonly ArCalibration $calibrationValidator,
        private readonly UploadArAsset $uploadArAsset,
        private readonly SubmitArAssetForReview $submitArAssetForReview,
        private readonly ApproveArAsset $approveArAsset,
        private readonly PublishArAsset $publishArAsset,
    ) {}

    /**
     * Validate, approve, and publish one frame-variant GLB in one operator flow.
     *
     * @param  array<string, mixed>  $calibration
     * @param  bool|int|string|null  $physicalMatchConfirmed  Raw form state; only literal true is accepted.
     */
    public function handle(
        ProductVariant $variant,
        ?UploadedFile $file,
        array $calibration,
        bool|int|string|null $physicalMatchConfirmed,
        User $actor,
    ): ArAsset {
        $this->authorizer->authorize($actor);

        if ($physicalMatchConfirmed !== true) {
            throw ValidationException::withMessages([
                'physical_match_confirmed' => 'Confirm that the GLB matches the physical frame before publishing.',
            ]);
        }

        $this->assertPublicationPreflight();

        try {
            return Cache::lock(
                $this->lockKey($variant),
                self::LOCK_SECONDS,
            )->block(self::LOCK_WAIT_SECONDS, function () use (
                $variant,
                $file,
                $calibration,
                $actor,
            ): ArAsset {
                return $this->publishWithinLock(
                    variant: $variant,
                    file: $file,
                    calibration: $calibration,
                    actor: $actor,
                );
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'asset' => 'Another 3D model publication is in progress for this variant. Try again shortly.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $calibration
     */
    private function publishWithinLock(
        ProductVariant $variant,
        ?UploadedFile $file,
        array $calibration,
        User $actor,
    ): ArAsset {
        $activeVariant = $this->activeVariant($variant);
        $candidates = $this->actionableCandidates($activeVariant);

        if ($candidates->count() > 1) {
            throw ValidationException::withMessages([
                'asset' => 'Multiple pending 3D model candidates need administrator resolution before publishing.',
            ]);
        }

        $candidate = $candidates->first();
        $normalizedCalibration = null;

        if ($candidate === null) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'file' => 'Select a GLB file before publishing a new 3D model.',
                ]);
            }

            $normalizedCalibration = $this->calibrationValidator->normalize($calibration);
            $candidate = $this->uploadArAsset->handle(
                variant: $activeVariant,
                file: $file,
                calibration: $normalizedCalibration,
                actor: $actor,
            );
        } elseif ($file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => 'Finish or resolve the existing pending 3D model before uploading another file.',
            ]);
        }

        if ($candidate->status === ArAssetStatus::Quarantined) {
            $normalizedCalibration ??= $this->calibrationForQuarantinedCandidate($candidate, $calibration);
            $candidate = $this->submitArAssetForReview->handle(
                asset: $candidate,
                calibration: $normalizedCalibration,
                actor: $actor,
            );
        }

        if ($candidate->status === ArAssetStatus::Validated) {
            $this->validatePersistedCalibration($candidate);
            $candidate = $this->approveArAsset->handle(
                asset: $candidate,
                actor: $actor,
                allowUploaderSelfApproval: true,
            );
        }

        if ($candidate->status !== ArAssetStatus::Approved) {
            throw ValidationException::withMessages([
                'asset' => 'The 3D model is not ready for publication. Review its current status and try again.',
            ]);
        }

        $this->validatePersistedCalibration($candidate);

        return $this->publishArAsset->handle($candidate, $actor);
    }

    private function activeVariant(ProductVariant $variant): ProductVariant
    {
        $activeVariant = ProductVariant::query()
            ->whereKey($variant->getKey())
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $query): Builder => $query
                ->active()
                ->where('product_type', 'frame'))
            ->lockForUpdate()
            ->first();

        if ($activeVariant === null) {
            throw ValidationException::withMessages([
                'variant' => 'Only active frame variants can publish a 3D model.',
            ]);
        }

        return $activeVariant;
    }

    /**
     * @return Collection<int, ArAsset>
     */
    private function actionableCandidates(ProductVariant $variant): Collection
    {
        return ArAsset::query()
            ->where('product_variant_id', $variant->getKey())
            ->whereIn('status', [
                ArAssetStatus::Quarantined->value,
                ArAssetStatus::Validated->value,
                ArAssetStatus::Approved->value,
            ])
            ->latest('version')
            ->lockForUpdate()
            ->limit(2)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $calibration
     * @return array<string, mixed>
     */
    private function calibrationForQuarantinedCandidate(ArAsset $candidate, array $calibration): array
    {
        if ($calibration === [] && is_array($candidate->calibration)) {
            return $this->calibrationValidator->normalize($candidate->calibration);
        }

        return $this->calibrationValidator->normalize($calibration);
    }

    private function validatePersistedCalibration(ArAsset $candidate): void
    {
        if (! is_array($candidate->calibration)) {
            throw ValidationException::withMessages([
                'calibration' => 'The pending 3D model has no valid calibration. Re-enter its measurements before publishing.',
            ]);
        }

        $this->calibrationValidator->normalize($candidate->calibration);
    }

    private function assertPublicationPreflight(): void
    {
        $baseUrl = rtrim((string) config('ar.assets.base_url'), '/');

        if (! str_starts_with($baseUrl, 'https://')) {
            throw ValidationException::withMessages([
                'asset' => 'A public HTTPS AR asset base URL is not configured.',
            ]);
        }

        $publishedDisk = (string) config('ar.assets.published_disk');

        if ($publishedDisk === '') {
            throw ValidationException::withMessages([
                'asset' => 'A publication storage disk is not configured.',
            ]);
        }

        try {
            Storage::disk($publishedDisk);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'asset' => 'The publication storage disk is not available.',
            ]);
        }
    }

    private function lockKey(ProductVariant $variant): string
    {
        return 'ar-asset-publication:variant:'.$variant->getKey();
    }
}
