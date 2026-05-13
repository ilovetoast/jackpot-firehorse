<?php

namespace App\Support;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;

/**
 * Signed/proxied CDN URLs for 3D preview assets (poster + future GLB viewer).
 *
 * Never returns raw storage keys; values are null when the resolver has no path.
 */
final class Preview3dDeliveryUrls
{
    /**
     * @return array{preview_3d_poster_url: string|null, preview_3d_viewer_url: string|null, preview_3d_revision: string}
     */
    public static function forAuthenticatedAsset(Asset $asset): array
    {
        $revision = Preview3dMetadata::cacheRevisionToken($asset->metadata ?? []);
        $meta = $asset->metadata ?? [];
        $p3 = is_array($meta['preview_3d'] ?? null) ? $meta['preview_3d'] : [];
        $expectsPoster = isset($p3['poster_path']) && is_string($p3['poster_path']) && trim($p3['poster_path']) !== '';
        $expectsViewer = isset($p3['viewer_path']) && is_string($p3['viewer_path']) && trim($p3['viewer_path']) !== '';

        $poster = self::safeDeliveryUrl($asset, AssetVariant::PREVIEW_3D_POSTER, 'poster', $expectsPoster);
        $viewer = self::safeDeliveryUrl($asset, AssetVariant::PREVIEW_3D_GLB, 'viewer', $expectsViewer);

        return [
            'preview_3d_poster_url' => $poster,
            'preview_3d_viewer_url' => $viewer,
            'preview_3d_revision' => $revision,
        ];
    }

    private static function safeDeliveryUrl(Asset $asset, AssetVariant $variant, string $role, bool $metadataExpectsObject): ?string
    {
        try {
            $url = $asset->deliveryUrl($variant, DeliveryContext::AUTHENTICATED);
            if ($url === '' && $metadataExpectsObject) {
                Log::warning('preview_3d.signed_url_empty', [
                    'event' => 'preview_3d.signed_url_empty',
                    'asset_id' => $asset->id,
                    'tenant_id' => $asset->tenant_id,
                    'role' => $role,
                    'variant' => $variant->value,
                ]);
            }

            return $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            Log::warning('preview_3d.signed_url_resolution_failed', [
                'event' => 'preview_3d.signed_url_resolution_failed',
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'role' => $role,
                'variant' => $variant->value,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
