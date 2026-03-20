<?php

namespace App\Support;

/**
 * Normalizes user-entered website URLs for crawling and validation.
 * Prepends https:// when no scheme is present (e.g. www.example.com, example.com/path).
 */
final class WebsiteUrlNormalizer
{
    /**
     * @return non-falsy-string|null Returns null when input is empty after trim.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $s = trim($input);
        if ($s === '') {
            return null;
        }

        // Already has a URI scheme (http, https, ftp, etc.)
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $s)) {
            return $s;
        }

        // "example.com" or "//example.com" → https://example.com
        $s = ltrim($s, '/');

        return 'https://'.$s;
    }
}
