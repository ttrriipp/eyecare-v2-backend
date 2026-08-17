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

class PublishArAsset
{
    public function __construct(
        private readonly ArAssetAuthorizer $authorizer,
        private readonly CreateAuditLog $createAuditLog,
        private readonly DatabaseManager $database,
    ) {}

    public function handle(ArAsset $asset, User $actor): ArAsset
    {
        $this->authorizer->authorize($actor);

        $contents = $this->verifiedQuarantineContents($asset);
        $baseUrl = rtrim((string) config('ar.assets.base_url'), '/');

        if (! str_starts_with($baseUrl, 'https://')) {
            throw ValidationException::withMessages([
                'asset' => 'A public HTTPS AR asset base URL is not configured.',
            ]);
        }

        $publishedPath = sprintf(
            'variants/%d/v%d/model.glb',
            $asset->product_variant_id,
            $asset->version,
        );
        $url = sprintf(
            '%s/%s/%d/v%d/model.glb',
            $baseUrl,
            trim((string) config('ar.assets.public_prefix', 'ar/variants'), '/'),
            $asset->product_variant_id,
            $asset->version,
        );
        $publishedDisk = (string) config('ar.assets.published_disk');
        $stored = false;

        try {
            if (Storage::disk($publishedDisk)->exists($publishedPath)
                || ! Storage::disk($publishedDisk)->put($publishedPath, $contents, 'public')) {
                throw ValidationException::withMessages([
                    'asset' => 'The immutable AR publication path is already in use.',
                ]);
            }

            $stored = true;

            return $this->database->transaction(function () use ($asset, $actor, $publishedPath, $url): ArAsset {
                $variant = ProductVariant::query()
                    ->whereKey($asset->product_variant_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedAsset = ArAsset::query()
                    ->whereKey($asset->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedAsset->status !== ArAssetStatus::Approved) {
                    throw ValidationException::withMessages([
                        'asset' => 'Only physically approved AR assets can be published.',
                    ]);
                }

                $previous = $variant->published_ar_asset_id === null
                    ? null
                    : ArAsset::query()
                        ->whereKey($variant->published_ar_asset_id)
                        ->lockForUpdate()
                        ->first();

                if ($previous !== null && $previous->is($lockedAsset)) {
                    return $previous;
                }

                if ($previous !== null) {
                    $previous->update([
                        'status' => ArAssetStatus::Superseded,
                        'superseded_at' => now(),
                    ]);
                }

                $lockedAsset->update([
                    'status' => ArAssetStatus::Published,
                    'published_path' => $publishedPath,
                    'url' => $url,
                    'published_by' => $actor->getKey(),
                    'published_at' => now(),
                ]);
                $variant->update(['published_ar_asset_id' => $lockedAsset->getKey()]);

                $this->createAuditLog->handle(
                    subject: $lockedAsset,
                    action: AuditEvent::ArAssetPublished,
                    metadata: [
                        'product_variant_id' => $variant->getKey(),
                        'version' => $lockedAsset->version,
                        'previous_asset_id' => $previous?->getKey(),
                    ],
                    actorId: $actor->getKey(),
                );

                if ($previous !== null) {
                    $this->createAuditLog->handle(
                        subject: $lockedAsset,
                        action: AuditEvent::ArAssetReplaced,
                        metadata: [
                            'product_variant_id' => $variant->getKey(),
                            'version' => $lockedAsset->version,
                            'previous_asset_id' => $previous->getKey(),
                        ],
                        actorId: $actor->getKey(),
                    );
                }

                return $lockedAsset->fresh();
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk($publishedDisk)->delete($publishedPath);
            }

            throw $exception;
        }
    }

    private function verifiedQuarantineContents(ArAsset $asset): string
    {
        $asset = $asset->fresh();

        if ($asset === null || $asset->byte_size === null || $asset->sha256 === null) {
            throw ValidationException::withMessages([
                'asset' => 'The AR asset integrity metadata is incomplete.',
            ]);
        }

        try {
            $contents = Storage::disk((string) config('ar.assets.quarantine_disk'))->get($asset->quarantine_path);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'asset' => 'The quarantined AR asset is unavailable.',
            ]);
        }

        if (strlen($contents) !== $asset->byte_size
            || ! hash_equals($asset->sha256, hash('sha256', $contents))) {
            throw ValidationException::withMessages([
                'asset' => 'The quarantined AR asset failed its integrity check.',
            ]);
        }

        return $contents;
    }
}
