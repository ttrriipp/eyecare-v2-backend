<?php

namespace App\Actions\ArAssets;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\ArAssetStatus;
use App\Enums\AuditEvent;
use App\Models\ArAsset;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ArAssets\ArAssetAuthorizer;
use App\Services\ArAssets\ArCalibration;
use App\Services\ArAssets\GlbValidator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class UploadArAsset
{
    public function __construct(
        private readonly ArAssetAuthorizer $authorizer,
        private readonly ArCalibration $calibrationValidator,
        private readonly GlbValidator $glbValidator,
        private readonly CreateAuditLog $createAuditLog,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * Store and validate a staff-selected GLB file in quarantine.
     *
     * Calibration is intentionally collected by the separate review-submission
     * step. A caller may provide it for pre-validation, but a valid upload
     * remains quarantined until it is explicitly submitted for review.
     *
     * @param  array<string, mixed>  $calibration
     */
    public function handle(
        ProductVariant $variant,
        UploadedFile $file,
        array $calibration,
        User $actor,
    ): ArAsset {
        $this->authorizer->authorize($actor);

        if (strtolower($file->getClientOriginalExtension()) !== 'glb') {
            throw ValidationException::withMessages([
                'file' => 'Only .glb files are accepted.',
            ]);
        }

        $maxBytes = (int) config('ar.assets.max_bytes', 10 * 1024 * 1024);
        $fileSize = $file->getSize();

        if ($fileSize === false || $fileSize > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => 'The GLB file exceeds the 10 MiB maximum size.',
            ]);
        }

        $contents = $file->get();

        if (! is_string($contents) || strlen($contents) > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => is_string($contents)
                    ? 'The GLB file exceeds the 10 MiB maximum size.'
                    : 'The uploaded file could not be read.',
            ]);
        }

        $quarantineDisk = (string) config('ar.assets.quarantine_disk');
        $quarantinePath = 'uploads/'.Str::uuid()->toString().'.glb';

        if (! Storage::disk($quarantineDisk)->put($quarantinePath, $contents, 'private')) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file could not be stored for validation.',
            ]);
        }

        try {
            $asset = $this->database->transaction(function () use ($variant, $actor, $quarantinePath, $contents): ArAsset {
                $lockedVariant = ProductVariant::query()
                    ->whereKey($variant->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $version = ((int) ArAsset::query()
                    ->where('product_variant_id', $lockedVariant->getKey())
                    ->max('version')) + 1;

                $asset = ArAsset::query()->create([
                    'product_variant_id' => $lockedVariant->getKey(),
                    'version' => $version,
                    'status' => ArAssetStatus::Quarantined,
                    'format' => 'glb',
                    'quarantine_path' => $quarantinePath,
                    'byte_size' => strlen($contents),
                    'sha256' => hash('sha256', $contents),
                    'uploaded_by' => $actor->getKey(),
                    'uploaded_at' => now(),
                ]);

                $this->createAuditLog->handle(
                    subject: $asset,
                    action: AuditEvent::ArAssetUploaded,
                    metadata: [
                        'product_variant_id' => $lockedVariant->getKey(),
                        'version' => $version,
                        'byte_size' => strlen($contents),
                    ],
                    actorId: $actor->getKey(),
                );

                return $asset;
            });
        } catch (Throwable $exception) {
            Storage::disk($quarantineDisk)->delete($quarantinePath);

            throw $exception;
        }

        try {
            $normalizedCalibration = $calibration === []
                ? null
                : $this->calibrationValidator->normalize($calibration);
            $this->glbValidator->validate($contents);

            $asset->update([
                'status' => ArAssetStatus::Quarantined,
                'calibration' => $normalizedCalibration,
                'validation_error' => null,
            ]);

            return $asset->fresh();
        } catch (Throwable $exception) {
            $message = $this->humanValidationMessage($exception);
            $asset->update([
                'status' => ArAssetStatus::Rejected,
                'validation_error' => $message,
            ]);

            $this->createAuditLog->handle(
                subject: $asset,
                action: AuditEvent::ArAssetRejected,
                metadata: [
                    'product_variant_id' => $asset->product_variant_id,
                    'version' => $asset->version,
                    'failure_class' => $exception::class,
                ],
                actorId: $actor->getKey(),
            );

            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'file' => $message,
            ]);
        }
    }

    private function humanValidationMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            $message = collect($exception->errors())->flatten()->first();

            if (is_string($message) && $message !== '') {
                return mb_substr($message, 0, 2000);
            }
        }

        if ($exception instanceof InvalidArgumentException && $exception->getMessage() !== '') {
            return mb_substr($exception->getMessage(), 0, 2000);
        }

        return 'The GLB file failed server-side validation. Review the file and try again.';
    }
}
