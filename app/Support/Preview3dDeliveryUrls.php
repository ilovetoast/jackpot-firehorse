<?php

namespace App\Support;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Signed/proxied CDN URLs for 3D preview assets (poster + future GLB viewer).
 *
 * Never returns raw storage keys; values are null when the resolver has no path.
 */
final class Preview3dDeliveryUrls
{
    /**
     * @return array{
     *     preview_3d_poster_url: string|null,
     *     preview_3d_viewer_url: string|null,
     *     preview_3d_revision: string,
     *     preview_3d_poster_is_stub: bool,
     *     preview_3d_poster_stub_reason: string|null,
     * }
     */
    public static function forAuthenticatedAsset(Asset $asset): array
    {
        $revision = Preview3dMetadata::cacheRevisionToken($asset->metadata ?? []);
        $meta = $asset->metadata ?? [];
        $p3 = is_array($meta['preview_3d'] ?? null) ? $meta['preview_3d'] : [];
        $dbg = is_array($p3['debug'] ?? null) ? $p3['debug'] : [];
        $posterIsStub = (bool) ($dbg['poster_stub'] ?? false);
        $failure = $p3['failure_message'] ?? null;
        $failureTrimmed = is_string($failure) ? trim($failure) : '';
        $stubReasonFromFailure = $posterIsStub && $failureTrimmed !== ''
            ? Str::limit($failureTrimmed, 280)
            : null;
        $stubReason = $stubReasonFromFailure ?? ($posterIsStub
            ? 'Raster 3D preview (Blender) did not produce an image; the file may still preview interactively in the browser.'
            : null);
        $expectsPoster = isset($p3['poster_path']) && is_string($p3['poster_path']) && trim($p3['poster_path']) !== '';
        $expectsViewer = isset($p3['viewer_path']) && is_string($p3['viewer_path']) && trim($p3['viewer_path']) !== '';

        $poster = self::safeDeliveryUrl($asset, AssetVariant::PREVIEW_3D_POSTER, 'poster', $expectsPoster);
        $viewer = self::safeDeliveryUrl($asset, AssetVariant::PREVIEW_3D_GLB, 'viewer', $expectsViewer);

        return [
            'preview_3d_poster_url' => $poster,
            'preview_3d_viewer_url' => $viewer,
            'preview_3d_revision' => $revision,
            'preview_3d_poster_is_stub' => $posterIsStub,
            'preview_3d_poster_stub_reason' => $stubReason,
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
