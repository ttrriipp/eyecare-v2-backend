<?php

namespace App\Models;

use App\Enums\ArAssetStatus;
use Database\Factories\ArAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Fillable([
    'product_variant_id',
    'version',
    'status',
    'format',
    'quarantine_path',
    'published_path',
    'url',
    'byte_size',
    'sha256',
    'calibration',
    'uploaded_by',
    'uploaded_at',
    'validated_at',
    'approved_by',
    'approved_at',
    'published_by',
    'published_at',
    'disabled_by',
    'disabled_at',
    'superseded_at',
    'expires_at',
    'validation_error',
])]
class ArAsset extends Model
{
    /** @use HasFactory<ArAssetFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    public function isPatientReady(): bool
    {
        if ($this->status !== ArAssetStatus::Published
            || ! is_string($this->url)
            || filter_var($this->url, FILTER_VALIDATE_URL) === false
            || parse_url($this->url, PHP_URL_SCHEME) !== 'https'
            || ! is_string($this->published_path)
            || ! is_int($this->byte_size)
            || $this->byte_size < 1
            || ! is_string($this->sha256)
            || preg_match('/\A[a-f0-9]{64}\z/', $this->sha256) !== 1
            || $this->format !== 'glb'
            || ! is_array($this->calibration)
            || ! $this->hasValidCalibration()
            || $this->expires_at?->isPast()) {
            return false;
        }

        try {
            $disk = Storage::disk((string) config('ar.assets.published_disk'));

            if (! $disk->exists($this->published_path)
                || $disk->size($this->published_path) !== $this->byte_size) {
                return false;
            }

            return hash_equals($this->sha256, hash('sha256', $disk->get($this->published_path)));
        } catch (Throwable) {
            return false;
        }
    }

    private function hasValidCalibration(): bool
    {
        foreach ([
            'frame_width_mm',
            'outer_frame_height_mm',
            'lens_width_mm',
            'lens_height_mm',
            'bridge_width_mm',
            'temple_length_mm',
        ] as $dimension) {
            if (! $this->isFiniteNumber($this->calibration[$dimension] ?? null)
                || $this->calibration[$dimension] <= 0) {
                return false;
            }
        }

        foreach (['scale', 'anchor', 'rotation_degrees'] as $transform) {
            $values = $this->calibration[$transform] ?? null;

            if (! is_array($values)
                || ! $this->isFiniteNumber($values['x'] ?? null)
                || ! $this->isFiniteNumber($values['y'] ?? null)
                || ! $this->isFiniteNumber($values['z'] ?? null)) {
                return false;
            }
        }

        return abs((float) $this->calibration['scale']['x']) >= PHP_FLOAT_EPSILON
            && abs((float) $this->calibration['scale']['y']) >= PHP_FLOAT_EPSILON
            && abs((float) $this->calibration['scale']['z']) >= PHP_FLOAT_EPSILON;
    }

    private function isFiniteNumber(mixed $value): bool
    {
        return ! is_bool($value)
            && (is_int($value) || is_float($value))
            && is_finite((float) $value);
    }

    /**
     * @return array{status: string, asset: array{url: string, format: string, version: int, byte_size: int, sha256: string}, calibration: array<string, mixed>}|null
     */
    public function toPatientArray(): ?array
    {
        if (! $this->isPatientReady()) {
            return null;
        }

        return [
            'status' => 'ready',
            'asset' => [
                'url' => $this->url,
                'format' => $this->format,
                'version' => $this->version,
                'byte_size' => $this->byte_size,
                'sha256' => $this->sha256,
            ],
            'calibration' => $this->calibration,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ArAssetStatus::class,
            'version' => 'integer',
            'byte_size' => 'integer',
            'calibration' => 'array',
            'uploaded_at' => 'datetime',
            'validated_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'disabled_at' => 'datetime',
            'superseded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
