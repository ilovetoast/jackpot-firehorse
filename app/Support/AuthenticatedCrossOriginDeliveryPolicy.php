<?php

namespace App\Support;

use App\Models\Asset;

/**
 * Authenticated app users normally load raster thumbnails via plain CDN URLs
 * plus CloudFront signed cookies. WebGL / MSE paths (&lt;model-viewer crossOrigin="anonymous"&gt;,
 * &lt;video crossOrigin="anonymous"&gt;) use CORS mode that omits cookies, so the CDN must
 * authorize via query-string signed URLs for those variants (same idea as PDF_PAGE).
 *
 * Audio playback (&lt;audio crossOrigin="anonymous"&gt; when the live analyser is on, and
 * {@see \App\Support\CdnAssetLoadDiagnostics::probeCdnAssetAvailability} `fetch` probes)
 * also omits cookies — plain tenant CDN URLs therefore 403 while the same URL works in a
 * new tab (navigation sends CloudFront cookies). {@see AssetVariant::AUDIO_WEB} and
 * audio {@see AssetVariant::ORIGINAL} must be signed the same way as {@see AssetVariant::VIDEO_WEB}.
 */
final class AuthenticatedCrossOriginDeliveryPolicy
{
    public static function requiresSignedCloudFrontUrl(AssetVariant $variant, Asset $asset): bool
    {
        return match ($variant) {
            AssetVariant::PREVIEW_3D_GLB => true,
            AssetVariant::VIDEO_WEB => true,
            AssetVariant::VIDEO_PREVIEW => true,
            AssetVariant::AUDIO_WEB => true,
            AssetVariant::ORIGINAL => self::assetIsGltfBinaryOriginal($asset)
                || self::assetIsAudioOriginalForCrossOriginPlayback($asset),
            default => false,
        };
    }

    /**
     * Original bytes for browser-streamed audio (fallback when `audio_web` derivative absent).
     * Mirrors the extension gate in {@see \App\Http\Controllers\AssetController::audioPlaybackUrl()}.
     */
    public static function assetIsAudioOriginalForCrossOriginPlayback(Asset $asset): bool
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if (str_starts_with($mime, 'audio/')) {
            return true;
        }

        $ext = strtolower((string) pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));

        return in_array($ext, ['mp3', 'wav', 'aac', 'm4a', 'ogg', 'flac', 'weba'], true);
    }

    public static function assetIsGltfBinaryOriginal(Asset $asset): bool
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if ($mime === 'model/gltf-binary') {
            return true;
        }

        $name = strtolower((string) ($asset->original_filename ?? ''));
        if (str_ends_with($name, '.glb')) {
            return true;
        }

        $path = strtolower((string) ($asset->storage_root_path ?? ''));
        if (str_ends_with($path, '.glb')) {
            return true;
        }

        return false;
    }
}
