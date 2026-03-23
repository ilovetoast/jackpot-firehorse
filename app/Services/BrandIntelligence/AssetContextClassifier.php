<?php

namespace App\Services\BrandIntelligence;

use App\Enums\AssetContextType;
use App\Models\Asset;

/**
 * Classifies an asset into a creative context using filename, title, and light metadata heuristics.
 * (Optional AI classification can be layered in later without changing callers.)
 */
final class AssetContextClassifier
{
    public function classify(Asset $asset): AssetContextType
    {
        $title = strtolower((string) ($asset->title ?? ''));
        $filename = strtolower((string) ($asset->original_filename ?? ''));
        $haystack = $title.' '.$filename;

        $meta = is_array($asset->metadata ?? null) ? $asset->metadata : [];
        $metaBits = [];
        foreach (['caption', 'description', 'campaign', 'channel', 'placement'] as $k) {
            if (isset($meta[$k]) && is_string($meta[$k]) && trim($meta[$k]) !== '') {
                $metaBits[] = $meta[$k];
            }
        }
        $metaStr = strtolower(implode(' ', $metaBits));

        $mime = strtolower((string) ($asset->mime_type ?? ''));
        $width = isset($meta['width']) && is_numeric($meta['width']) ? (int) $meta['width'] : null;
        $height = isset($meta['height']) && is_numeric($meta['height']) ? (int) $meta['height'] : null;

        if ($mime === 'image/svg+xml' || str_contains($haystack, 'logo') || str_contains($haystack, 'lockup') || str_contains($haystack, 'mark')) {
            if ($mime === 'image/svg+xml' || ($width !== null && $height !== null && $width <= 512 && $height <= 512)) {
                return AssetContextType::LOGO_ONLY;
            }
        }

        if (preg_match('/\b(instagram|tiktok|story|social|reel|snap|feed post)\b/', $haystack)
            || ($metaStr !== '' && preg_match('/\b(instagram|tiktok|story|social)\b/', $metaStr))) {
            return AssetContextType::SOCIAL_POST;
        }

        if (preg_match('/\b(banner|display|programmatic|ppc|ad_|_ad|728|970|300x250|leaderboard| MPU)\b/i', $haystack)) {
            return AssetContextType::DIGITAL_AD;
        }

        if (preg_match('/\b(lifestyle|editorial|outdoor|location|environment|people|portrait|candid|street)\b/', $haystack)
            || ($metaStr !== '' && preg_match('/\b(lifestyle|outdoor|editorial)\b/', $metaStr))) {
            return AssetContextType::LIFESTYLE;
        }

        if (preg_match('/\b(hero|product|packshot|pack|sku|ecommerce|catalog| PDP| PLP)\b/i', $haystack)) {
            return AssetContextType::PRODUCT_HERO;
        }

        return AssetContextType::OTHER;
    }
}
