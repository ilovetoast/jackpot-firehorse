<?php

namespace App\Services\Metadata;

/**
 * Normalizes metadata values for filter harvest and display.
 * Ensures scalar fields (e.g. dominant_color_bucket) always get a single string;
 * handles legacy values incorrectly stored as arrays.
 */
class MetadataValueNormalizer
{
    /**
     * Normalize a value to a single scalar suitable for filter options.
     * - Scalar (string|int|float|bool) → string
     * - Array with one element → that element as scalar
     * - Array with multiple elements → null (invalid for scalar field)
     * - null / empty array → null
     *
     * @param mixed $value
     * @return string|null
     */
    public static function normalizeScalar($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $count = count($value);
            if ($count === 0) {
                return null;
            }
            if ($count === 1) {
                return self::normalizeScalar(reset($value));
            }
            // Multiple elements: not a valid scalar
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}
