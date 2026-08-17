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
use Illuminate\Validation\ValidationException;

class DisableArAsset
{
    public function __construct(
        private readonly ArAssetAuthorizer $authorizer,
        private readonly CreateAuditLog $createAuditLog,
        private readonly DatabaseManager $database,
    ) {}

    public function handle(ArAsset $asset, User $actor): ArAsset
    {
        $this->authorizer->authorize($actor);

        return $this->database->transaction(function () use ($asset, $actor): ArAsset {
            $variant = ProductVariant::query()
                ->whereKey($asset->product_variant_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAsset = ArAsset::query()
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($variant->published_ar_asset_id !== $lockedAsset->getKey()
                || $lockedAsset->status !== ArAssetStatus::Published) {
                throw ValidationException::withMessages([
                    'asset' => 'Only the current published AR asset can be disabled.',
                ]);
            }

            $lockedAsset->update([
                'status' => ArAssetStatus::Disabled,
                'disabled_by' => $actor->getKey(),
                'disabled_at' => now(),
            ]);
            $variant->update(['published_ar_asset_id' => null]);

            $this->createAuditLog->handle(
                subject: $lockedAsset,
                action: AuditEvent::ArAssetDisabled,
                metadata: [
                    'product_variant_id' => $variant->getKey(),
                    'version' => $lockedAsset->version,
                ],
                actorId: $actor->getKey(),
            );

            return $lockedAsset->fresh();
        });
    }
}
