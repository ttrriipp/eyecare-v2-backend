<?php

namespace App\Actions\ArAssets;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\ArAssetStatus;
use App\Enums\AuditEvent;
use App\Models\ArAsset;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ArAssets\ArAssetAuthorizer;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class RollbackArAsset
{
    public function __construct(
        private readonly ArAssetAuthorizer $authorizer,
        private readonly CreateAuditLog $createAuditLog,
        private readonly DatabaseManager $database,
    ) {}

    public function handle(ArAsset $asset, User $actor): ArAsset
    {
        $this->authorizer->authorize($actor);

        if (! $asset->isPatientReady() && $asset->status !== ArAssetStatus::Disabled && $asset->status !== ArAssetStatus::Superseded) {
            throw ValidationException::withMessages([
                'asset' => 'Only a previously published AR asset can be restored.',
            ]);
        }

        return $this->database->transaction(function () use ($asset, $actor): ArAsset {
            $variant = ProductVariant::query()
                ->whereKey($asset->product_variant_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAsset = ArAsset::query()
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedAsset->status, [ArAssetStatus::Disabled, ArAssetStatus::Superseded], true)) {
                throw ValidationException::withMessages([
                    'asset' => 'Only a previously published AR asset can be restored.',
                ]);
            }

            if (! $this->publishedFileIsValid($lockedAsset)) {
                throw ValidationException::withMessages([
                    'asset' => 'The previous AR asset is no longer available or valid.',
                ]);
            }

            $previous = $variant->published_ar_asset_id === null
                ? null
                : ArAsset::query()
                    ->whereKey($variant->published_ar_asset_id)
                    ->lockForUpdate()
                    ->first();

            if ($previous !== null && $previous->isNot($lockedAsset)) {
                $previous->update([
                    'status' => ArAssetStatus::Superseded,
                    'superseded_at' => now(),
                ]);
            }

            $lockedAsset->update([
                'status' => ArAssetStatus::Published,
                'disabled_by' => null,
                'disabled_at' => null,
                'superseded_at' => null,
                'published_by' => $actor->getKey(),
                'published_at' => now(),
            ]);
            $variant->update(['published_ar_asset_id' => $lockedAsset->getKey()]);

            $this->createAuditLog->handle(
                subject: $lockedAsset,
                action: AuditEvent::ArAssetRolledBack,
                metadata: [
                    'product_variant_id' => $variant->getKey(),
                    'version' => $lockedAsset->version,
                    'replaced_asset_id' => $previous?->getKey(),
                ],
                actorId: $actor->getKey(),
            );

            return $lockedAsset->fresh();
        });
    }

    private function publishedFileIsValid(ArAsset $asset): bool
    {
        if ($asset->published_path === null || $asset->byte_size === null || $asset->sha256 === null) {
            return false;
        }

        try {
            $disk = Storage::disk((string) config('ar.assets.published_disk'));

            return $disk->exists($asset->published_path)
                && $disk->size($asset->published_path) === $asset->byte_size
                && hash_equals($asset->sha256, hash('sha256', $disk->get($asset->published_path)));
        } catch (Throwable) {
            return false;
        }
    }
}
