<?php

namespace App\Support\Typography;

/**
 * Builds Google Fonts CSS URLs from Brand DNA typography font entries.
 *
 * @see EditorBrandContextController::googleFontStylesheetUrlsFromTypography
 */
final class GoogleFontStylesheetHelper
{
    /**
     * Default css2 URL (weights 300–700) when no custom stylesheet_url is set.
     */
    public static function defaultStylesheetUrlForFamily(string $familyName): string
    {
        return 'https://fonts.googleapis.com/css2?family='.rawurlencode(trim($familyName)).':wght@300;400;500;600;700&display=swap';
    }

    /**
     * @param  array<string, mixed>  $fontEntry  One item from model_payload.typography.fonts[]
     */
    public static function stylesheetUrlForGoogleFontEntry(array $fontEntry): ?string
    {
        if (($fontEntry['source'] ?? '') !== 'google') {
            return null;
        }

        $custom = trim((string) ($fontEntry['stylesheet_url'] ?? ''));
        if ($custom !== '' && str_starts_with($custom, 'https://')) {
            return $custom;
        }

        $name = trim((string) ($fontEntry['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        return self::defaultStylesheetUrlForFamily($name);
    }
}
