<?php

namespace App\Studio\Rendering;

/**
 * Maps legacy editor CSS stacks (first family token + weight) to bundled registry slugs (without bundled: prefix).
 */
final class StudioLegacyFontFamilyMapper
{
    /**
     * @return non-empty-string|null
     */
    public static function bundledSlugFor(string $fontFamily, ?int $fontWeight): ?string
    {
        $token = self::firstFamilyToken($fontFamily);
        if ($token === '') {
            return null;
        }
        $bold = $fontWeight !== null && $fontWeight >= 600;
        /** @var array<string, string> $map */
        $map = is_array(config('studio_rendering.fonts.legacy_family_token_map', []))
            ? config('studio_rendering.fonts.legacy_family_token_map', [])
            : [];
        $stem = $map[strtolower($token)] ?? null;
        if ($stem === null || $stem === '') {
            return null;
        }
        $candidates = $bold
            ? [$stem.'-bold', $stem.'-regular', $stem]
            : [$stem.'-regular', $stem.'-bold', $stem];
        foreach ($candidates as $slug) {
            if (self::bundledPathForSlug($slug) !== null) {
                return $slug;
            }
        }

        return null;
    }

    private static function bundledPathForSlug(string $slug): ?string
    {
        /** @var array<string, array<string, mixed>> $bundled */
        $bundled = is_array(config('studio_rendering.fonts.bundled', []))
            ? config('studio_rendering.fonts.bundled', [])
            : [];
        if (! isset($bundled[$slug]['path'])) {
            return null;
        }
        $p = trim((string) $bundled[$slug]['path']);

        return $p !== '' && is_file($p) ? $p : null;
    }

    private static function firstFamilyToken(string $fontFamily): string
    {
        $s = trim($fontFamily);
        if ($s === '') {
            return '';
        }
        $parts = preg_split('/\s*,\s*/', $s) ?: [];

        return strtolower(trim((string) ($parts[0] ?? ''), " '\""));
    }
}
