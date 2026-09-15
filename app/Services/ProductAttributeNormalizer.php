<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class ProductAttributeNormalizer
{
    public const int MAX_ENTRIES = 20;

    public const int MAX_KEY_LENGTH = 50;

    public const int MAX_VALUE_LENGTH = 255;

    /**
     * Normalize a generic key/value attribute array.
     *
     * - Trims keys and values.
     * - Converts keys to snake_case.
     * - Removes rows where both key and value are blank.
     * - Rejects blank or duplicate normalized keys.
     * - Preserves values as strings without type inference.
     *
     * @param  array<string, string>  $attributes
     * @return array<string, string>
     */
    public function normalize(array $attributes): array
    {
        $normalized = [];
        $seenKeys = [];

        foreach ($attributes as $key => $value) {
            $key = $this->toSnakeCase(trim((string) $key));
            $value = trim((string) $value);

            if ($key === '' && $value === '') {
                continue;
            }

            if ($key === '') {
                throw ValidationException::withMessages([
                    'attributes' => ['Attribute keys cannot be blank.'],
                ]);
            }

            if (mb_strlen($key) > self::MAX_KEY_LENGTH) {
                throw ValidationException::withMessages([
                    'attributes' => ["Attribute key \"{$key}\" exceeds maximum length of ".self::MAX_KEY_LENGTH.'.'],
                ]);
            }

            if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                throw ValidationException::withMessages([
                    'attributes' => ["Value for \"{$key}\" exceeds maximum length of ".self::MAX_VALUE_LENGTH.'.'],
                ]);
            }

            if (in_array($key, $seenKeys, true)) {
                throw ValidationException::withMessages([
                    'attributes' => ["Duplicate attribute key \"{$key}\" after normalization."],
                ]);
            }

            $seenKeys[] = $key;
            $normalized[$key] = $value;
        }

        if (count($normalized) > self::MAX_ENTRIES) {
            throw ValidationException::withMessages([
                'attributes' => ['Maximum '.self::MAX_ENTRIES.' attribute entries allowed.'],
            ]);
        }

        return $normalized;
    }

    /**
     * Convert a string to snake_case.
     */
    private function toSnakeCase(string $value): string
    {
        $value = preg_replace('/[\s\-]+/', '_', $value);
        $value = preg_replace('/([a-z])([A-Z])/', '$1_$2', $value);

        return strtolower($value);
    }
}
