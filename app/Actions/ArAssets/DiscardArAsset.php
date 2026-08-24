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

class DiscardArAsset
{
    public function __construct(
        private readonly ArAssetAuthorizer $authorizer,
        private readonly CreateAuditLog $createAuditLog,
        private readonly DatabaseManager $database,
    ) {}

    public function handle(ArAsset $asset, User $actor): ArAsset
    {
        $this->authorizer->authorize($actor);

        $discarded = $this->database->transaction(function () use ($asset, $actor): ArAsset {
            $variant = ProductVariant::query()
                ->whereKey($asset->product_variant_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAsset = ArAsset::query()
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedAsset->status, [
                ArAssetStatus::Quarantined,
                ArAssetStatus::Validated,
                ArAssetStatus::Approved,
                ArAssetStatus::Rejected,
            ], true) || $variant->published_ar_asset_id === $lockedAsset->getKey()) {
                throw ValidationException::withMessages([
                    'asset' => 'Only unpublished 3D model uploads can be discarded. Disable the current model or roll back a previous version instead.',
                ]);
            }

            $previousStatus = $lockedAsset->status->value;
            $lockedAsset->update(['status' => ArAssetStatus::Discarded]);

            $this->createAuditLog->handle(
                subject: $lockedAsset,
                action: AuditEvent::ArAssetDiscarded,
                metadata: [
                    'product_variant_id' => $variant->getKey(),
                    'version' => $lockedAsset->version,
                    'previous_status' => $previousStatus,
                ],
                actorId: $actor->getKey(),
            );

            return $lockedAsset->fresh();
        });

        try {
            $disk = Storage::disk((string) config('ar.assets.quarantine_disk'));

            if ($disk->exists($discarded->quarantine_path)
                && ! $disk->delete($discarded->quarantine_path)) {
                report(new \RuntimeException('The discarded AR quarantine file could not be removed.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $discarded;
    }
}
