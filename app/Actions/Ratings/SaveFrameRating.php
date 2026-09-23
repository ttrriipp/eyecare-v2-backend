<?php

namespace App\Actions\Ratings;

use App\Actions\Notifications\NotifyAdminUsers;
use App\Models\DispensingEvent;
use App\Models\FrameRating;
use App\Models\Patient;
use App\Models\ProductVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SaveFrameRating
{
    public function __construct(
        private readonly NotifyAdminUsers $notifyAdminUsers,
    ) {}

    /**
     * Create or update a frame rating. Eligibility derives from dispensing.
     *
     * One current rating per patient per dispensed frame. Edits update in place.
     */
    public function handle(
        Patient $patient,
        ProductVariant $variant,
        int $rating,
        ?string $comment = null,
        ?DispensingEvent $dispensingEvent = null,
        bool $publicDisplayConsent = false,
        ?UploadedFile $attachment = null,
        bool $publicAttachmentConsent = false,
    ): FrameRating {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages([
                'rating' => ['Rating must be between 1 and 5.'],
            ]);
        }

        $publicDisplayConsentAt = $publicDisplayConsent && trim($comment ?? '') !== ''
            ? now()
            : null;
        $attachmentsDisk = null;
        $newAttachmentPath = null;
        $newAttachmentPublicId = null;
        $newAttachmentMimeType = null;

        if ($attachment !== null) {
            $diskName = (string) config('filesystems.product_review_attachments_disk', 'product_review_attachments');
            $attachmentsDisk = Storage::disk($diskName);
            $newAttachmentPath = $attachment->store('ratings', [
                'disk' => $diskName,
                'visibility' => 'private',
            ]);

            if (! is_string($newAttachmentPath) || $newAttachmentPath === '') {
                throw new RuntimeException('Product review attachment storage is unavailable.');
            }

            $newAttachmentPublicId = (string) Str::uuid();
            $newAttachmentMimeType = $attachment->getMimeType();
        }

        $shouldNotify = false;
        $previousAttachmentPath = null;

        try {
            $frameRating = DB::transaction(function () use (
                $patient,
                $variant,
                $rating,
                $comment,
                $dispensingEvent,
                $publicDisplayConsentAt,
                $publicAttachmentConsent,
                $newAttachmentPath,
                $newAttachmentPublicId,
                $newAttachmentMimeType,
                &$shouldNotify,
                &$previousAttachmentPath,
            ): FrameRating {
                $existing = FrameRating::query()
                    ->where('patient_id', $patient->id)
                    ->where('product_variant_id', $variant->id)
                    ->lockForUpdate()
                    ->first();

                $previousAttachmentPath = $existing?->attachment_path;
                $attachmentPath = $newAttachmentPath ?? $existing?->attachment_path;
                $attachmentPublicId = $newAttachmentPublicId ?? $existing?->attachment_public_id;
                $attachmentMimeType = $newAttachmentMimeType ?? $existing?->attachment_mime_type;
                $publicAttachmentConsentAt = $publicAttachmentConsent && $attachmentPath !== null
                    ? now()
                    : null;

                $attributes = [
                    'rating' => $rating,
                    'comment' => $comment,
                    'public_display_consent_at' => $publicDisplayConsentAt,
                    'attachment_path' => $attachmentPath,
                    'attachment_public_id' => $attachmentPublicId,
                    'attachment_mime_type' => $attachmentMimeType,
                    'public_attachment_consent_at' => $publicAttachmentConsentAt,
                ];

                if ($existing !== null) {
                    $shouldNotify = $rating <= 2
                        && ($existing->rating !== $rating || $existing->comment !== $comment);

                    $existing->update($attributes);

                    return $existing->fresh();
                }

                $shouldNotify = $rating <= 2;

                return FrameRating::query()->create([
                    'patient_id' => $patient->id,
                    'product_variant_id' => $variant->id,
                    'dispensing_event_id' => $dispensingEvent?->id,
                    ...$attributes,
                ]);
            });
        } catch (Throwable $exception) {
            if ($newAttachmentPath !== null && $attachmentsDisk !== null) {
                try {
                    $attachmentsDisk->delete($newAttachmentPath);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }

        if ($newAttachmentPath !== null && $previousAttachmentPath !== null && $attachmentsDisk !== null) {
            try {
                $attachmentsDisk->delete($previousAttachmentPath);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($shouldNotify) {
            $this->notifyAdminUsers->lowFrameRating($frameRating);
        }

        return $frameRating;
    }
}
