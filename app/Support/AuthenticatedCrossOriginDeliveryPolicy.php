<?php

namespace App\Support;

use App\Models\Asset;

/**
 * Authenticated app users normally load raster thumbnails via plain CDN URLs
 * plus CloudFront signed cookies. WebGL / MSE paths (&lt;model-viewer crossOrigin="anonymous"&gt;,
 * &lt;video crossOrigin="anonymous"&gt;) use CORS mode that omits cookies, so the CDN must
 * authorize via query-string signed URLs for those variants (same idea as PDF_PAGE).
 */
final class AuthenticatedCrossOriginDeliveryPolicy
{
    public static function requiresSignedCloudFrontUrl(AssetVariant $variant, Asset $asset): bool
    {
        return match ($variant) {
            AssetVariant::PREVIEW_3D_GLB => true,
            AssetVariant::VIDEO_WEB => true,
            AssetVariant::VIDEO_PREVIEW => true,
            AssetVariant::ORIGINAL => self::assetIsGltfBinaryOriginal($asset),
            default => false,
        };
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
