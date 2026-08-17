<?php

namespace App\Actions\ArAssets;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\ArAssetStatus;
use App\Enums\AuditEvent;
use App\Models\ArAsset;
use App\Models\User;
use App\Services\ArAssets\ArAssetAuthorizer;
use App\Services\ArAssets\ArCalibration;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

class SubmitArAssetForReview
{
    public function __construct(
        private readonly ArAssetAuthorizer $authorizer,
        private readonly ArCalibration $calibrationValidator,
        private readonly CreateAuditLog $createAuditLog,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * Validate steward calibration and place a quarantined asset in the
     * physical-review queue.
     *
     * @param  array<string, mixed>  $calibration
     */
    public function handle(ArAsset $asset, array $calibration, User $actor): ArAsset
    {
        $this->authorizer->authorize($actor);

        try {
            $normalizedCalibration = $this->calibrationValidator->normalize($calibration);
        } catch (ValidationException $exception) {
            $this->reject($asset, $actor, $this->validationMessage($exception));

            throw $exception;
        }

        return $this->database->transaction(function () use ($asset, $actor, $normalizedCalibration): ArAsset {
            $lockedAsset = ArAsset::query()
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAsset->status !== ArAssetStatus::Quarantined) {
                throw ValidationException::withMessages([
                    'asset' => 'Only a received 3D upload can be submitted for physical review.',
                ]);
            }

            $lockedAsset->update([
                'status' => ArAssetStatus::Validated,
                'calibration' => $normalizedCalibration,
                'validated_at' => now(),
                'validation_error' => null,
            ]);

            $this->createAuditLog->handle(
                subject: $lockedAsset,
                action: AuditEvent::ArAssetValidated,
                metadata: [
                    'product_variant_id' => $lockedAsset->product_variant_id,
                    'version' => $lockedAsset->version,
                ],
                actorId: $actor->getKey(),
            );

            return $lockedAsset->fresh();
        });
    }

    private function reject(ArAsset $asset, User $actor, string $message): void
    {
        $this->database->transaction(function () use ($asset, $actor, $message): void {
            $lockedAsset = ArAsset::query()
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAsset->status !== ArAssetStatus::Quarantined) {
                return;
            }

            $lockedAsset->update([
                'status' => ArAssetStatus::Rejected,
                'validation_error' => $message,
            ]);

            $this->createAuditLog->handle(
                subject: $lockedAsset,
                action: AuditEvent::ArAssetRejected,
                metadata: [
                    'product_variant_id' => $lockedAsset->product_variant_id,
                    'version' => $lockedAsset->version,
                ],
                actorId: $actor->getKey(),
            );
        });
    }

    private function validationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())->flatten()->first();

        return is_string($message) && $message !== ''
            ? mb_substr($message, 0, 2000)
            : 'The physical calibration could not be validated.';
    }
}
