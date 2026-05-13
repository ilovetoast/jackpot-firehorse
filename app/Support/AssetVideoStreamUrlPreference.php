<?php

namespace App\Support;

use App\Models\Asset;

/**
 * Resolves the preferred URL for drawer/lightbox full-length video streaming.
 *
 * VIDEO_PREVIEW is hover-only / truncated. VIDEO_WEB is full-length browser playback.
 */
final class AssetVideoStreamUrlPreference
{
    public static function resolvePlaybackUrl(Asset $asset, string $deliveryContext = 'authenticated'): string
    {
        $meta = $asset->metadata['video'] ?? [];
        if (is_array($meta)
            && ($meta['web_playback_status'] ?? null) === 'ready'
            && is_string($meta['web_playback_path'] ?? null)
            && trim((string) $meta['web_playback_path']) !== '') {
            $u = $asset->deliveryUrl(AssetVariant::VIDEO_WEB, $deliveryContext);
            if ($u !== '') {
                return $u;
            }
        }

        $u = $asset->deliveryUrl(AssetVariant::ORIGINAL, $deliveryContext);
        if ($u !== '') {
            return $u;
        }

        return $asset->deliveryUrl(AssetVariant::VIDEO_PREVIEW, $deliveryContext);
    }
}
