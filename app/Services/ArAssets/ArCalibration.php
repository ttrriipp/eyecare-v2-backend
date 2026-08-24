<?php

namespace App\Services\ArAssets;

use Illuminate\Validation\ValidationException;

class ArCalibration
{
    /**
     * @var array<string, string>
     */
    private const DIMENSIONS = [
        'frame_width_mm' => 'frame width',
        'outer_frame_height_mm' => 'outer frame height',
        'lens_width_mm' => 'lens width',
        'lens_height_mm' => 'lens height',
        'bridge_width_mm' => 'bridge width',
        'temple_length_mm' => 'temple length',
    ];

    /**
     * @var array<string, string>
     */
    private const TRANSFORMS = [
        'scale' => 'scale',
        'anchor' => 'anchor',
        'rotation_degrees' => 'rotation degrees',
    ];

    /**
     * Normalize and validate the public calibration contract.
     *
     * @param  array<string, mixed>  $calibration
     * @return array<string, mixed>
     */
    public function normalize(array $calibration): array
    {
        $normalized = [];

        foreach (self::DIMENSIONS as $key => $label) {
            $normalized[$key] = $this->positiveNumber($calibration[$key] ?? null, $label);
        }

        foreach (self::TRANSFORMS as $key => $label) {
            $transform = $calibration[$key] ?? null;

            if (! is_array($transform)) {
                throw ValidationException::withMessages([
                    'calibration' => "The {$label} transform must contain x, y, and z values.",
                ]);
            }

            foreach (['x', 'y', 'z'] as $axis) {
                $normalized[$key][$axis] = $this->number(
                    $transform[$axis] ?? null,
                    "{$label} {$axis}",
                );
            }
        }

        if (abs($normalized['scale']['x']) < PHP_FLOAT_EPSILON
            || abs($normalized['scale']['y']) < PHP_FLOAT_EPSILON
            || abs($normalized['scale']['z']) < PHP_FLOAT_EPSILON) {
            throw ValidationException::withMessages([
                'calibration' => 'Scale values must be non-zero.',
            ]);
        }

        return $normalized;
    }

    /**
     * Adjust renderer scale so a measured transformed model matches the
     * physical frame width while preserving the current scale proportions.
     *
     * @param  array<string, mixed>  $calibration
     * @return array<string, mixed>
     */
    public function adjustScaleToMeasuredWidth(array $calibration, mixed $measuredRenderedWidthMm): array
    {
        $normalized = $this->normalize($calibration);
        $measuredWidth = $this->positiveNumber(
            value: $measuredRenderedWidthMm,
            label: 'measured rendered width',
            errorKey: 'measured_rendered_width_mm',
        );
        $factor = $normalized['frame_width_mm'] / $measuredWidth;

        if (! is_finite($factor) || $factor <= 0) {
            throw ValidationException::withMessages([
                'measured_rendered_width_mm' => 'The measured rendered width cannot produce a finite scale adjustment.',
            ]);
        }

        foreach (['x', 'y', 'z'] as $axis) {
            $normalized['scale'][$axis] *= $factor;
        }

        return $this->normalize($normalized);
    }

    private function positiveNumber(mixed $value, string $label, string $errorKey = 'calibration'): float
    {
        $number = $this->number($value, $label, $errorKey);

        if ($number <= 0) {
            throw ValidationException::withMessages([
                $errorKey => "The {$label} must be greater than zero.",
            ]);
        }

        return $number;
    }

    private function number(mixed $value, string $label, string $errorKey = 'calibration'): float
    {
        if (is_bool($value)
            || ! is_int($value) && ! is_float($value) && ! is_string($value)
            || ! is_numeric($value)) {
            throw ValidationException::withMessages([
                $errorKey => "The {$label} must be a finite number.",
            ]);
        }

        $number = (float) $value;

        if (! is_finite($number)) {
            throw ValidationException::withMessages([
                $errorKey => "The {$label} must be a finite number.",
            ]);
        }

        return $number;
    }
}
