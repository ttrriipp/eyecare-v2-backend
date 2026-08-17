<?php

namespace App\Actions\ArAssets;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\ArAssetStatus;
use App\Enums\AuditEvent;
use App\Models\ArAsset;
use App\Models\User;
use App\Services\ArAssets\ArAssetAuthorizer;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

class ApproveArAsset
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
            $lockedAsset = ArAsset::query()
                ->with('variant')
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->authorizer->authorize($actor);

            if ($lockedAsset->status === ArAssetStatus::Approved) {
                return $lockedAsset;
            }

            if ($lockedAsset->status !== ArAssetStatus::Validated) {
                throw ValidationException::withMessages([
                    'asset' => 'Only validated AR assets can be approved.',
                ]);
            }

            if ($lockedAsset->uploaded_by !== null
                && (int) $lockedAsset->uploaded_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages([
                    'asset' => 'The staff member who uploaded this model cannot approve its physical review.',
                ]);
            }

            $lockedAsset->update([
                'status' => ArAssetStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ]);

            $this->createAuditLog->handle(
                subject: $lockedAsset,
                action: AuditEvent::ArAssetApproved,
                metadata: [
                    'product_variant_id' => $lockedAsset->product_variant_id,
                    'version' => $lockedAsset->version,
                ],
                actorId: $actor->getKey(),
            );

            return $lockedAsset->fresh();
        });
    }
}
