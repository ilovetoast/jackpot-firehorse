<?php

namespace App\Studio\Rendering;

use App\Models\Asset;
use App\Models\Tenant;
use App\Studio\Rendering\Exceptions\StudioFontResolutionException;
use App\Support\EditorAssetOriginalBytesLoader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Copies tenant font {@see Asset} bytes from Laravel-managed storage into a deterministic local cache path.
 * Never exposes signed URLs or HTTP fetches to rasterizers.
 */
final class StudioRenderingFontFileCache
{
    /**
     * @return non-empty-string Absolute path to a readable .ttf/.otf under the font cache directory.
     */
    public function materializeFromAsset(
        Tenant $tenant,
        ?int $compositionBrandId,
        Asset $asset,
        string $objectKey,
    ): string {
        $ext = $this->allowedExtensionFromKey($objectKey, $asset);
        $cacheKey = $this->buildCacheKeySuffix($asset, $objectKey);
        $dir = $this->fontCacheRoot();
        File::ensureDirectoryExists($dir);
        $tenantSeg = 't'.preg_replace('/[^0-9]/', '', (string) $tenant->id);
        $brandSeg = 'b'.($compositionBrandId !== null ? (string) (int) $compositionBrandId : '0');
        $assetSeg = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $asset->id);
        $fileName = $tenantSeg.'_'.$brandSeg.'_a'.$assetSeg.'_'.$cacheKey.$ext;
        $fullPath = $dir.DIRECTORY_SEPARATOR.$fileName;

        if (is_file($fullPath) && is_readable($fullPath) && filesize($fullPath) > 32) {
            if (config('studio_rendering.font_pipeline_verbose_log')) {
                Log::info('[FONT_DEBUG] Tenant font staged', [
                    'asset_id' => (string) $asset->id,
                    'local_path' => $fullPath,
                    'exists' => true,
                    'readable' => true,
                    'extension' => strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)),
                    'cache' => 'hit',
                ]);
            }

            return $fullPath;
        }

        try {
            $bytes = EditorAssetOriginalBytesLoader::loadFromStorage($asset, $objectKey);
        } catch (\Throwable $e) {
            throw new StudioFontResolutionException(
                'font_cache_read_failed',
                'Failed to read font bytes from storage: '.$e->getMessage(),
                ['asset_id' => (string) $asset->id, 'object_key' => $objectKey],
                $e,
            );
        }

        if ($bytes === '') {
            throw new StudioFontResolutionException('font_cache_empty', 'Font file in storage was empty.', [
                'asset_id' => (string) $asset->id,
            ]);
        }

        File::ensureDirectoryExists(dirname($fullPath));
        if (file_put_contents($fullPath, $bytes) === false) {
            throw new StudioFontResolutionException('font_cache_write_failed', 'Could not write font to local cache.', [
                'target' => $fullPath,
            ]);
        }
        @chmod($fullPath, 0644);
        if (! is_readable($fullPath)) {
            throw new StudioFontResolutionException('font_cache_not_readable', 'Cached font file is not readable.', [
                'path' => $fullPath,
            ]);
        }

        if (config('studio_rendering.font_pipeline_verbose_log')) {
            Log::info('[FONT_DEBUG] Tenant font staged', [
                'asset_id' => (string) $asset->id,
                'local_path' => $fullPath,
                'exists' => is_file($fullPath),
                'readable' => is_readable($fullPath),
                'extension' => strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)),
                'cache' => 'miss_written',
            ]);
        }

        return $fullPath;
    }

    /**
     * @return non-empty-string
     */
    public function fontCacheRoot(): string
    {
        $raw = trim((string) config('studio_rendering.font_cache_dir', 'studio/font-cache'));
        $sub = trim($raw, DIRECTORY_SEPARATOR.'/\\');

        return storage_path('app'.DIRECTORY_SEPARATOR.$sub);
    }

    /**
     * Deterministic suffix so replaced fonts invalidate cache entries.
     */
    public function buildCacheKeySuffix(Asset $asset, string $objectKey): string
    {
        $ver = $asset->currentVersion()->first();
        $parts = [
            'aid' => (string) $asset->id,
            'key' => $objectKey,
            'v_id' => $ver !== null ? (string) $ver->id : '',
            'v_upd' => $ver?->updated_at?->getTimestamp() ?? 0,
            'v_sz' => (string) (int) ($ver?->file_size ?? $asset->file_size ?? 0),
            'a_upd' => $asset->updated_at?->getTimestamp() ?? 0,
        ];
        $h = hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));

        return substr($h, 0, 20);
    }

    private function allowedExtensionFromKey(string $objectKey, Asset $asset): string
    {
        $allowed = $this->allowedExtensionsList();
        $fromKey = strtolower(pathinfo($objectKey, PATHINFO_EXTENSION));
        if (in_array($fromKey, $allowed, true)) {
            return '.'.$fromKey;
        }
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if (str_contains($mime, 'ttf')) {
            return '.ttf';
        }
        if (str_contains($mime, 'otf') || str_contains($mime, 'font-sfnt')) {
            return '.otf';
        }

        $this->throwUnsupportedExtension($fromKey, $objectKey);

        return '.ttf';
    }

    /**
     * @return list<string> lowercase without dot
     */
    private function allowedExtensionsList(): array
    {
        $raw = trim((string) config('studio_rendering.allowed_font_extensions', 'ttf,otf'));
        $parts = array_filter(array_map('trim', explode(',', strtolower($raw))));

        return $parts !== [] ? array_values($parts) : ['ttf', 'otf'];
    }

    /**
     * @throws StudioFontResolutionException
     */
    private function throwUnsupportedExtension(string $ext, string $hintPath): void
    {
        $bad = ['woff', 'woff2', 'eot', 'svg'];
        if (in_array($ext, $bad, true)) {
            throw new StudioFontResolutionException(
                'unsupported_font_extension',
                'Font extension ".'.$ext.'" is not supported for native export (use TTF or OTF).',
                ['path_hint' => $hintPath, 'extension' => $ext],
            );
        }

        throw new StudioFontResolutionException(
            'unsupported_font_extension',
            'Could not determine a supported font extension (TTF/OTF) for this asset.',
            ['path_hint' => $hintPath, 'extension' => $ext],
        );
    }
}
