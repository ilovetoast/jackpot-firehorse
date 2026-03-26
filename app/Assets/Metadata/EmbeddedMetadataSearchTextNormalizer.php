<?php

namespace App\Assets\Metadata;

/**
 * Deterministic search_text normalization: case-fold, accent strip (when intl available),
 * collapse punctuation/slashes/dashes to spaces for consistent keyword matching.
 */
class EmbeddedMetadataSearchTextNormalizer
{
    public function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_convert_encoding') && ! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        if (class_exists(\Normalizer::class)) {
            $nfd = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($nfd)) {
                $value = preg_replace('/\p{M}/u', '', $nfd) ?? $value;
            }
        }

        $value = mb_strtolower($value);
        // Keep word chars and numbers across scripts; turn punctuation into spaces (24-70mm, f/2.8, ©)
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value;
    }
}
