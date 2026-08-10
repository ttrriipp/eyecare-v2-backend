<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ContactLensAttributeValidator
{
    /**
     * Canonical contact-lens attribute keys.
     *
     * Only parameters relevant to a particular lens are required.
     */
    public const array CANONICAL_KEYS = [
        'power',
        'base_curve',
        'diameter',
        'cylinder',
        'axis',
        'add',
        'color',
        'pack_size',
    ];

    /**
     * Validate contact-lens attributes against canonical keys.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed> Validated and sanitized attributes
     */
    public function validate(array $attributes): array
    {
        // Filter to only canonical keys
        $filtered = array_intersect_key($attributes, array_flip(self::CANONICAL_KEYS));

        $validator = Validator::make($filtered, [
            'power' => ['nullable', 'string', 'max:20'],
            'base_curve' => ['nullable', 'numeric', 'min:7', 'max:12'],
            'diameter' => ['nullable', 'numeric', 'min:10', 'max:20'],
            'cylinder' => ['nullable', 'string', 'max:20'],
            'axis' => ['nullable', 'integer', 'min:0', 'max:180'],
            'add' => ['nullable', 'string', 'max:20'],
            'color' => ['nullable', 'string', 'max:50'],
            'pack_size' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Check if the product is a contact-lens type.
     */
    public function isContactLens(string $productType): bool
    {
        return $productType === 'contact_lens';
    }

    /**
     * Get applicable attributes from a variant's attributes array.
     *
     * Returns only the canonical keys that are present.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function getApplicableAttributes(array $attributes): array
    {
        return array_filter(
            array_intersect_key($attributes, array_flip(self::CANONICAL_KEYS)),
            fn ($value) => $value !== null && $value !== '',
        );
    }
}
