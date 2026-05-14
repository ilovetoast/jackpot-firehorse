<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\AssetVersion;

/**
 * Canonical metadata.preview_3d shape for 3D DAM assets.
 *
 * All writers should merge through {@see merge} so keys stay consistent.
 */
final class Preview3dMetadata
{
    public const STATUS_NONE = 'none';

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public const CANONICAL_FORMAT = 'glb';

    /**
     * @return array<string, mixed>
     */
    public static function defaultShape(): array
    {
        return [
            'status' => self::STATUS_NONE,
            'source_extension' => null,
            'canonical_format' => self::CANONICAL_FORMAT,
            'viewer_path' => null,
            'thumbnail_path' => null,
            'poster_path' => null,
            'conversion_required' => false,
            'conversion_driver' => 'none',
            'triangle_count' => null,
            'vertex_count' => null,
            'texture_count' => null,
            'max_texture_dimension' => null,
            'file_size_bytes' => null,
            'skip_reason' => null,
            'failure_message' => null,
            'debug' => [
                'inspected_at' => null,
                'render_seconds' => null,
                'conversion_seconds' => null,
                'poster_stub' => null,
                'poster_generated_at' => null,
                'blender_used' => null,
                'blender_version' => null,
            ],
            'disable_realtime_viewer' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public static function merge(array $existing, array $patch): array
    {
        $base = self::defaultShape();
        $merged = array_replace_recursive($base, $existing, $patch);
        if (! is_array($merged['debug'] ?? null)) {
            $merged['debug'] = $base['debug'];
        }

        return $merged;
    }

    /**
     * Resolve the storage key for native GLB viewer delivery (Phase 5C).
     *
     * Only the version/original object path is accepted; must be a non-URL key ending in `.glb`.
     * Call only when the registry type is `model_glb`.
     */
    public static function safeNativeGlbViewerStorageKey(Asset $asset, ?AssetVersion $version): ?string
    {
        $candidate = null;
        if ($version instanceof AssetVersion) {
            $fp = $version->file_path ?? null;
            if (is_string($fp) && trim($fp) !== '') {
                $candidate = trim($fp);
            }
        }
        if ($candidate === null) {
            $root = $asset->storage_root_path ?? null;
            if (is_string($root) && trim($root) !== '') {
                $candidate = trim($root);
            }
        }
        if ($candidate === null || $candidate === '') {
            return null;
        }
        if (str_contains($candidate, '://')) {
            return null;
        }
        if (str_starts_with(strtolower($candidate), 'http://') || str_starts_with(strtolower($candidate), 'https://')) {
            return null;
        }
        if (strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION)) !== 'glb') {
            return null;
        }

        return $candidate;
    }

    /**
     * True when asset or current version metadata marks the 3D raster poster as a pipeline stub
     * (e.g. Blender unavailable on the worker). Thumbnail_status may still be "completed".
     */
    public static function assetOrVersionHasPosterStub(Asset $asset): bool
    {
        $asset->loadMissing('currentVersion');
        foreach ([$asset->metadata ?? [], $asset->currentVersion?->metadata ?? []] as $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $debug = $meta['preview_3d']['debug'] ?? null;
            if (is_array($debug) && (($debug['poster_stub'] ?? false) === true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Initial preview_3d blob after upload / inspection (Phase 1–3).
     *
     * @param  array<string, mixed>  $registryCapabilities  file_types.types.<key>.capabilities
     * @param  string|null  $nativeGlbViewerStorageKey  Phase 5C: original GLB S3 key for `model_glb` only; otherwise null
     * @return array<string, mixed>
     */
    public static function initialFromInspection(
        string $sourceExtension,
        ?int $fileSizeBytes,
        array $registryCapabilities,
        ?string $nativeGlbViewerStorageKey = null,
    ): array {
        $conversionRequired = (bool) ($registryCapabilities['conversion_required'] ?? false);
        $status = self::STATUS_PENDING;
        $skipReason = null;
        if ($conversionRequired && ! (bool) config('dam_3d.enabled')) {
            $status = self::STATUS_SKIPPED;
            $skipReason = 'conversion_requires_dam_3d';
        }

        $patch = [
            'status' => $status,
            'source_extension' => strtolower($sourceExtension),
            'conversion_required' => $conversionRequired,
            'conversion_driver' => $conversionRequired ? 'blender' : 'none',
            'file_size_bytes' => $fileSizeBytes,
            'skip_reason' => $skipReason,
            'failure_message' => null,
            'debug' => [
                'inspected_at' => now()->toIso8601String(),
            ],
        ];
        if (is_string($nativeGlbViewerStorageKey) && trim($nativeGlbViewerStorageKey) !== ''
            && strtolower($sourceExtension) === 'glb') {
            $patch['viewer_path'] = trim($nativeGlbViewerStorageKey);
        }

        return self::merge([], $patch);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forThumbnailJobSkipped(
        string $thumbnailSkipReason,
        ?string $thumbnailSkipMessage,
    ): array {
        return self::merge([], [
            'status' => self::STATUS_SKIPPED,
            'skip_reason' => $thumbnailSkipReason,
            'failure_message' => $thumbnailSkipMessage,
            'debug' => [
                'inspected_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Storage keys for 3D preview derivatives that should be deleted with thumbnails / on asset removal.
     *
     * Excludes {@code viewer_path} when it equals {@code $storageRootPath} (native GLB uses the original object).
     *
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    public static function derivativeStorageKeysForCleanup(array $metadata, ?string $storageRootPath): array
    {
        $p3 = $metadata['preview_3d'] ?? null;
        if (! is_array($p3)) {
            return [];
        }
        $root = is_string($storageRootPath) ? trim($storageRootPath) : '';
        $keys = [];
        foreach (['poster_path', 'thumbnail_path'] as $field) {
            $k = $p3[$field] ?? null;
            if (is_string($k) && trim($k) !== '') {
                $keys[] = trim($k);
            }
        }
        $vp = $p3['viewer_path'] ?? null;
        if (is_string($vp) && trim($vp) !== '') {
            $t = trim($vp);
            if ($root === '' || $t !== $root) {
                $keys[] = $t;
            }
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * Short opaque token for cache-busting / memo invalidation when preview_3d blobs change.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function cacheRevisionToken(array $metadata): string
    {
        $p3 = $metadata['preview_3d'] ?? null;
        if (! is_array($p3)) {
            return '';
        }
        $dbg = is_array($p3['debug'] ?? null) ? $p3['debug'] : [];
        $blob = implode('|', [
            (string) ($p3['status'] ?? ''),
            (string) ($p3['poster_path'] ?? ''),
            (string) ($p3['viewer_path'] ?? ''),
            (string) ($p3['skip_reason'] ?? ''),
            (string) ($p3['failure_message'] ?? ''),
            ($dbg['poster_stub'] ?? false) ? '1' : '0',
            (string) ($dbg['poster_generated_at'] ?? ''),
        ]);

        return substr(hash('sha256', $blob), 0, 12);
    }

    /**
     * Sanitized admin/support payload: booleans and status strings only (no raw storage keys).
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    /**
     * Subset of preview_3d safe for tenant workspace JSON (no storage keys or paths).
     *
     * @param  array<string, mixed>  $preview3d
     * @return array<string, mixed>
     */
    public static function workspacePublicSnapshot(array $preview3d): array
    {
        $dbg = is_array($preview3d['debug'] ?? null) ? $preview3d['debug'] : [];

        return array_filter([
            'status' => isset($preview3d['status']) ? (string) $preview3d['status'] : null,
            'source_extension' => isset($preview3d['source_extension']) ? (string) $preview3d['source_extension'] : null,
            'canonical_format' => isset($preview3d['canonical_format']) ? (string) $preview3d['canonical_format'] : null,
            'conversion_required' => isset($preview3d['conversion_required']) ? (bool) $preview3d['conversion_required'] : null,
            'skip_reason' => isset($preview3d['skip_reason']) && is_string($preview3d['skip_reason']) ? $preview3d['skip_reason'] : null,
            'failure_message' => isset($preview3d['failure_message']) && is_string($preview3d['failure_message']) ? $preview3d['failure_message'] : null,
            'poster_stub' => (bool) ($dbg['poster_stub'] ?? false),
            'blender_used' => (bool) ($dbg['blender_used'] ?? false),
        ], static fn ($v) => $v !== null);
    }

    public static function adminDebugSummary(array $metadata, bool $dam3dEnabled): array
    {
        $p3 = $metadata['preview_3d'] ?? null;
        if (! is_array($p3)) {
            return [
                'status' => self::STATUS_NONE,
                'viewer_path_present' => false,
                'poster_path_present' => false,
                'poster_stub' => false,
                'blender_used' => false,
                'viewer_enabled' => false,
                'failure_message' => null,
                'skip_reason' => null,
            ];
        }
        $dbg = is_array($p3['debug'] ?? null) ? $p3['debug'] : [];
        $viewerKey = isset($p3['viewer_path']) && is_string($p3['viewer_path']) && trim($p3['viewer_path']) !== '';
        $posterKey = isset($p3['poster_path']) && is_string($p3['poster_path']) && trim($p3['poster_path']) !== '';

        return [
            'status' => (string) ($p3['status'] ?? self::STATUS_NONE),
            'viewer_path_present' => $viewerKey,
            'poster_path_present' => $posterKey,
            'poster_stub' => (bool) ($dbg['poster_stub'] ?? false),
            'blender_used' => (bool) ($dbg['blender_used'] ?? false),
            'viewer_enabled' => $dam3dEnabled && $viewerKey,
            'failure_message' => isset($p3['failure_message']) && is_string($p3['failure_message'])
                ? $p3['failure_message'] : null,
            'skip_reason' => isset($p3['skip_reason']) && is_string($p3['skip_reason'])
                ? $p3['skip_reason'] : null,
        ];
    }
}
