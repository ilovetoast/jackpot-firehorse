<?php

namespace App\Studio\Rendering;

use App\Models\Asset;
use App\Models\Tenant;
use App\Studio\Rendering\Dto\ResolvedStudioFont;
use App\Studio\Rendering\Exceptions\StudioFontResolutionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves a concrete local TTF/OTF path for text rasterization (never remote URLs).
 *
 * Resolution order:
 * 1. font_key / fontKey (bundled: | google: | tenant:)
 * 2. font_asset_id / fontAssetId / nested font.asset_id (tenant {@see Asset} staged via {@see StudioRenderingFontFileCache})
 * 3. Explicit local paths (dev-only)
 * 4. font.disk + font.storage_path on allow-listed disks
 * 5. Legacy font_family → bundled catalog / font_family_map
 * 6. Effective default font path ({@see StudioRenderingFontPaths})
 * 7. Hard-coded DejaVuSans when readable (last resort for missing default)
 */
final class StudioRenderingFontResolver
{
    public function __construct(
        private StudioRenderingFontFileCache $fontFileCache,
        private StudioGoogleFontFileCache $googleFontFileCache,
    ) {}

    /**
     * @param  array<string, mixed>  $textLayerExtra  {@see RenderLayer::$extra} for a text layer
     */
    public function resolveForTextLayer(
        Tenant $tenant,
        ?int $compositionBrandId,
        array $textLayerExtra,
        string $fontFamilyFromStyle,
        ?string $layerId = null,
    ): ResolvedStudioFont {
        $hadExplicit = $this->computeExplicitFontSelection($textLayerExtra);
        $fontWeight = isset($textLayerExtra['font_weight']) ? (int) $textLayerExtra['font_weight'] : null;
        $debug = [
            'font_family' => $fontFamilyFromStyle,
            'font_weight' => $fontWeight,
            'had_explicit_font_selection' => $hadExplicit,
        ];

        try {
            $keyRead = $this->readFontKey($textLayerExtra);
            if ($keyRead !== null) {
                $resolved = $this->resolveFontKey($tenant, $compositionBrandId, $keyRead, $hadExplicit, $debug, $layerId);
                if ($resolved !== null) {
                    return $resolved;
                }
            }

            $path = $this->tryTenantFontAsset($tenant, $compositionBrandId, $textLayerExtra);
            if ($path !== null) {
                $this->assertLocalFontFile($path, 'tenant_asset');
                $this->logFontResolved($layerId, $path, 'tenant_asset', $keyRead);

                return new ResolvedStudioFont($path, 'tenant_asset', $hadExplicit, array_merge($debug, [
                    'resolved_path' => $path,
                ]), $keyRead);
            }

            $path = $this->tryExplicitLocalPath($textLayerExtra, $hadExplicit);
            if ($path !== null) {
                $this->assertLocalFontFile($path, 'explicit_path');
                $this->logFontResolved($layerId, $path, 'explicit_path', $keyRead);

                return new ResolvedStudioFont($path, 'explicit_path', $hadExplicit, array_merge($debug, [
                    'resolved_path' => $path,
                ]), $keyRead);
            }

            $path = $this->tryLocalDiskFontPath($textLayerExtra, $hadExplicit);
            if ($path !== null) {
                $this->assertLocalFontFile($path, 'local_disk_font');
                $this->logFontResolved($layerId, $path, 'local_disk_font', $keyRead);

                return new ResolvedStudioFont($path, 'local_disk_font', $hadExplicit, array_merge($debug, [
                    'resolved_path' => $path,
                ]), $keyRead);
            }

            if ($hadExplicit) {
                throw new StudioFontResolutionException(
                    'font_explicit_unresolved',
                    'An explicit font was selected for this text layer but no usable font file could be resolved.',
                    $debug,
                );
            }

            $slug = StudioLegacyFontFamilyMapper::bundledSlugFor($fontFamilyFromStyle, $fontWeight);
            if ($slug !== null) {
                $p = StudioRenderingFontPaths::bundledPathForSlug($slug);
                if ($p !== null) {
                    $this->assertLocalFontFile($p, 'legacy_bundled');
                    $fullKey = 'bundled:'.$slug;
                    $this->logFontResolved($layerId, $p, 'legacy_bundled', $fullKey);

                    return new ResolvedStudioFont($p, 'legacy_bundled', false, array_merge($debug, [
                        'resolved_path' => $p,
                        'resolved_font_key' => $fullKey,
                    ]), $fullKey);
                }
            }

            $path = $this->tryFamilyMap($fontFamilyFromStyle);
            if ($path !== null) {
                $this->assertLocalFontFile($path, 'family_map');
                $this->logFontResolved($layerId, $path, 'family_map', $keyRead);

                return new ResolvedStudioFont($path, 'family_map', false, array_merge($debug, [
                    'resolved_path' => $path,
                ]), $keyRead);
            }

            $path = $this->tryDefaultFontPathChain();
            $this->assertLocalFontFile($path, 'default');
            $this->logFontResolved($layerId, $path, 'default', $keyRead);

            return new ResolvedStudioFont($path, 'default', false, array_merge($debug, [
                'resolved_path' => $path,
            ]), $keyRead);
        } catch (StudioFontResolutionException $e) {
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $textLayerExtra
     */
    private function resolveFontKey(
        Tenant $tenant,
        ?int $compositionBrandId,
        string $fullKey,
        bool $hadExplicit,
        array $debug,
        ?string $layerId,
    ): ?ResolvedStudioFont {
        $fullKey = trim($fullKey);
        if ($fullKey === '') {
            return null;
        }
        if (str_starts_with($fullKey, 'bundled:')) {
            $slug = substr($fullKey, strlen('bundled:'));
            $p = StudioRenderingFontPaths::bundledPathForSlug($slug);
            if ($p === null) {
                if ($hadExplicit) {
                    throw new StudioFontResolutionException('bundled_font_missing', 'Bundled font file is missing for key "'.$fullKey.'".', [
                        'font_key' => $fullKey,
                    ]);
                }

                return null;
            }
            $this->assertLocalFontFile($p, 'font_key_bundled');
            $this->logFontResolved($layerId, $p, 'font_key_bundled', $fullKey);

            return new ResolvedStudioFont($p, 'font_key_bundled', $hadExplicit, array_merge($debug, [
                'resolved_path' => $p,
                'resolved_font_key' => $fullKey,
            ]), $fullKey);
        }
        if (str_starts_with($fullKey, 'google:')) {
            $slug = substr($fullKey, strlen('google:'));
            /** @var array<string, array<string, mixed>> $google */
            $google = is_array(config('studio_rendering.fonts.google', []))
                ? config('studio_rendering.fonts.google', [])
                : [];
            if (! isset($google[$slug]['download_url'])) {
                if ($hadExplicit) {
                    throw new StudioFontResolutionException('google_font_unknown', 'Unknown curated Google font key "'.$fullKey.'".', [
                        'font_key' => $fullKey,
                    ]);
                }

                return null;
            }
            $url = trim((string) $google[$slug]['download_url']);
            $p = $this->googleFontFileCache->materializeFromRegistrySlug($slug, $url);
            $this->assertLocalFontFile($p, 'font_key_google');
            $this->logFontResolved($layerId, $p, 'font_key_google', $fullKey);

            return new ResolvedStudioFont($p, 'font_key_google', $hadExplicit, array_merge($debug, [
                'resolved_path' => $p,
                'resolved_font_key' => $fullKey,
            ]), $fullKey);
        }
        if (str_starts_with($fullKey, 'tenant:')) {
            $id = trim(substr($fullKey, strlen('tenant:')));
            if ($id === '') {
                if ($hadExplicit) {
                    throw new StudioFontResolutionException('tenant_font_key_empty', 'tenant: font key is missing an asset id.', []);
                }

                return null;
            }
            $path = $this->tryTenantFontAsset($tenant, $compositionBrandId, ['font_asset_id' => $id]);
            if ($path === null) {
                throw new StudioFontResolutionException('tenant_font_unresolved', 'Could not resolve tenant font key "'.$fullKey.'".', [
                    'font_key' => $fullKey,
                ]);
            }
            $this->assertLocalFontFile($path, 'font_key_tenant');
            $this->logFontResolved($layerId, $path, 'font_key_tenant', $fullKey);

            return new ResolvedStudioFont($path, 'font_key_tenant', $hadExplicit, array_merge($debug, [
                'resolved_path' => $path,
                'resolved_font_key' => $fullKey,
            ]), $fullKey);
        }

        if ($hadExplicit) {
            throw new StudioFontResolutionException('font_key_unknown_prefix', 'Unsupported font_key prefix in "'.$fullKey.'".', [
                'font_key' => $fullKey,
            ]);
        }

        return null;
    }

    private function logFontResolved(?string $layerId, string $fontPath, string $source, ?string $fontKey): void
    {
        if (! config('studio_rendering.font_pipeline_verbose_log')) {
            return;
        }
        Log::info('[FONT_DEBUG] Resolved font', [
            'layer_id' => $layerId,
            'font_key' => $fontKey,
            'resolved_path' => $fontPath,
            'exists' => file_exists($fontPath),
            'readable' => is_readable($fontPath),
            'source' => $source,
        ]);
    }

    /**
     * @param  array<string, mixed>  $textLayerExtra
     */
    public function layerHasExplicitCustomFontSelection(array $textLayerExtra): bool
    {
        return $this->computeExplicitFontSelection($textLayerExtra);
    }

    /**
     * @param  array<string, mixed>  $textLayerExtra
     */
    private function computeExplicitFontSelection(array $e): bool
    {
        foreach (['font_key', 'fontKey'] as $k) {
            if (isset($e[$k]) && is_string($e[$k]) && trim($e[$k]) !== '') {
                return true;
            }
        }
        foreach (['font_local_path', 'fontLocalPath', 'font_file_path', 'fontFilePath', 'resolved_font_path', 'resolvedFontPath'] as $k) {
            if (isset($e[$k]) && is_string($e[$k]) && trim($e[$k]) !== '') {
                return true;
            }
        }
        foreach (['font_asset_id', 'fontAssetId', 'brand_font_id', 'brandFontId'] as $k) {
            if (isset($e[$k]) && is_string($e[$k]) && trim($e[$k]) !== '') {
                return true;
            }
            if (isset($e[$k]) && is_int($e[$k]) && $e[$k] > 0) {
                return true;
            }
        }
        $font = is_array($e['font'] ?? null) ? $e['font'] : [];
        foreach (['asset_id', 'assetId'] as $k) {
            if (isset($font[$k]) && (is_string($font[$k]) || is_int($font[$k])) && trim((string) $font[$k]) !== '') {
                return true;
            }
        }
        $sp = $font['storage_path'] ?? $font['storagePath'] ?? null;
        $dk = $font['disk'] ?? null;
        if (is_string($sp) && trim($sp) !== '' && is_string($dk) && trim($dk) !== '') {
            return true;
        }
        $dTop = $e['font_disk'] ?? $e['fontDisk'] ?? null;
        $pTop = $e['font_storage_path'] ?? $e['fontStoragePath'] ?? null;
        if (is_string($dTop) && trim($dTop) !== '' && is_string($pTop) && trim($pTop) !== '') {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $textLayerExtra
     */
    private function readFontKey(array $e): ?string
    {
        foreach (['font_key', 'fontKey'] as $k) {
            if (isset($e[$k]) && is_string($e[$k])) {
                $s = trim($e[$k]);
                if ($s !== '') {
                    return $s;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $e
     */
    private function tryExplicitLocalPath(array $e, bool $hadExplicit): ?string
    {
        foreach (['font_local_path', 'fontLocalPath', 'font_file_path', 'fontFilePath', 'resolved_font_path', 'resolvedFontPath'] as $k) {
            if (! isset($e[$k]) || ! is_string($e[$k])) {
                continue;
            }
            $p = trim($e[$k]);
            if ($p === '' || ! $this->looksLikeAbsolutePath($p)) {
                continue;
            }
            if (! is_file($p)) {
                if ($hadExplicit) {
                    throw new StudioFontResolutionException('explicit_font_path_missing', 'Explicit font path does not exist or is not a file: '.$p, ['path' => $p]);
                }

                continue;
            }

            return $p;
        }

        return null;
    }

    private function looksLikeAbsolutePath(string $p): bool
    {
        if (str_starts_with($p, '/')) {
            return true;
        }
        if (strlen($p) > 2 && ctype_alpha($p[0]) && $p[1] === ':' && ($p[2] === '\\' || $p[2] === '/')) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $e
     */
    private function tryTenantFontAsset(Tenant $tenant, ?int $compositionBrandId, array $e): ?string
    {
        $assetId = $this->readFontAssetId($e);
        if ($assetId === null || $assetId === '') {
            return null;
        }

        $asset = Asset::query()->where('id', $assetId)->where('tenant_id', $tenant->id)->first();
        if ($asset === null) {
            throw new StudioFontResolutionException(
                'font_asset_not_found',
                'Font asset not found for id "'.$assetId.'" in this tenant.',
                ['font_asset_id' => $assetId],
            );
        }
        if ($compositionBrandId !== null && $asset->brand_id !== null && (int) $asset->brand_id !== (int) $compositionBrandId) {
            throw new StudioFontResolutionException(
                'font_asset_wrong_brand',
                'Font asset belongs to a different brand than this composition.',
                [
                    'font_asset_id' => $assetId,
                    'asset_brand_id' => $asset->brand_id,
                    'composition_brand_id' => $compositionBrandId,
                ],
            );
        }

        $ver = $asset->currentVersion()->first();
        $rel = $ver?->file_path ?? null;
        if (! is_string($rel) || $rel === '') {
            $rel = is_string($asset->storage_root_path ?? null) ? $asset->storage_root_path : null;
        }
        if (! is_string($rel) || $rel === '') {
            throw new StudioFontResolutionException(
                'font_asset_no_storage_path',
                'Font asset has no file_path / version file_path in storage.',
                ['font_asset_id' => $assetId],
            );
        }

        return $this->fontFileCache->materializeFromAsset($tenant, $compositionBrandId, $asset, $rel);
    }

    /**
     * @param  array<string, mixed>  $e
     */
    private function readFontAssetId(array $e): ?string
    {
        foreach (['font_asset_id', 'fontAssetId', 'brand_font_id', 'brandFontId'] as $k) {
            if (isset($e[$k]) && (is_string($e[$k]) || is_int($e[$k]))) {
                $s = trim((string) $e[$k]);
                if ($s !== '') {
                    return $s;
                }
            }
        }
        $font = is_array($e['font'] ?? null) ? $e['font'] : [];
        foreach (['asset_id', 'assetId'] as $k) {
            if (isset($font[$k]) && (is_string($font[$k]) || is_int($font[$k]))) {
                $s = trim((string) $font[$k]);
                if ($s !== '') {
                    return $s;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $textLayerExtra
     */
    private function tryLocalDiskFontPath(array $e, bool $hadExplicit): ?string
    {
        $font = is_array($e['font'] ?? null) ? $e['font'] : [];
        $disk = $this->stringKey($e, $font, ['font_disk', 'fontDisk', 'disk']);
        $path = $this->stringKey($e, $font, ['font_storage_path', 'fontStoragePath', 'storage_path', 'storagePath']);
        if ($disk === null || $path === null) {
            return null;
        }
        $disk = trim($disk);
        $path = trim($path);
        if ($disk === '' || $path === '') {
            return null;
        }
        $allowedDisks = config('studio_rendering.font_direct_read_disks', ['local', 'public']);
        if (! is_array($allowedDisks)) {
            $allowedDisks = ['local', 'public'];
        }
        if (! in_array($disk, $allowedDisks, true)) {
            throw new StudioFontResolutionException(
                'font_remote_disk_requires_asset_id',
                'Font disk "'.$disk.'" is not allowed for path-based font loading without an asset id. Use font_asset_id and tenant font assets for S3.',
                ['disk' => $disk],
            );
        }
        if (str_contains($path, '..')) {
            throw new StudioFontResolutionException('font_storage_path_invalid', 'Font storage path must not contain "..".', []);
        }
        if (! Storage::disk($disk)->exists($path)) {
            if ($hadExplicit) {
                throw new StudioFontResolutionException('font_storage_path_missing', 'Font file not found on disk "'.$disk.'": '.$path, []);
            }

            return null;
        }
        $absRoot = Storage::disk($disk)->path('');
        $full = rtrim($absRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR.'/\\');
        if (! is_file($full)) {
            if ($hadExplicit) {
                throw new StudioFontResolutionException('font_storage_path_not_file', 'Resolved font path is not a file: '.$full, []);
            }

            return null;
        }
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $allowedExt = $this->allowedExtensionsList();
        if (! in_array($ext, $allowedExt, true)) {
            throw new StudioFontResolutionException(
                'unsupported_font_extension',
                'Font extension ".'.$ext.'" is not supported (allowed: '.implode(', ', $allowedExt).').',
                ['path' => $full],
            );
        }

        return $full;
    }

    /**
     * @param  array<string, mixed>  $top
     * @param  array<string, mixed>  $font
     * @param  list<string>  $keys
     */
    private function stringKey(array $top, array $font, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($top[$k]) && is_string($top[$k]) && trim($top[$k]) !== '') {
                return trim($top[$k]);
            }
            if (isset($font[$k]) && is_string($font[$k]) && trim($font[$k]) !== '') {
                return trim($font[$k]);
            }
        }

        return null;
    }

    private function tryFamilyMap(string $fontFamily): ?string
    {
        $token = $this->firstFamilyToken($fontFamily);
        /** @var array<string, string> $map */
        $map = is_array(config('studio_rendering.font_family_map', []))
            ? config('studio_rendering.font_family_map', [])
            : [];
        if ($token !== '' && isset($map[$token])) {
            $p = trim((string) $map[$token]);
            if ($p !== '' && is_file($p)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * @throws StudioFontResolutionException
     */
    private function tryDefaultFontPathChain(): string
    {
        $path = StudioRenderingFontPaths::effectiveDefaultFontPath();
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
        $fallback = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        if (is_file($fallback) && is_readable($fallback)) {
            return $fallback;
        }
        throw new StudioFontResolutionException(
            'missing_default_font_path',
            'No usable default font path: bundled default, STUDIO_RENDERING_DEFAULT_FONT_PATH, and DejaVuSans.ttf are all missing or unreadable.',
            ['attempted' => $path],
        );
    }

    /**
     * @return list<string>
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
    private function assertLocalFontFile(string $path, string $context): void
    {
        if (! str_starts_with($path, '/') && ! (strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':')) {
            throw new StudioFontResolutionException(
                'font_path_not_absolute',
                'Font path must be absolute and local for native rendering (got non-absolute path). Context: '.$context,
                ['path' => $path],
            );
        }
        if (! is_file($path)) {
            throw new StudioFontResolutionException('cached_font_not_readable', 'Font path is not a file: '.$path, ['path' => $path]);
        }
        if (! is_readable($path)) {
            throw new StudioFontResolutionException('cached_font_not_readable', 'Font file is not readable: '.$path, ['path' => $path]);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed = $this->allowedExtensionsList();
        if (! in_array($ext, $allowed, true)) {
            throw new StudioFontResolutionException(
                'unsupported_font_extension',
                'Font file has unsupported extension ".'.$ext.'" (allowed: '.implode(', ', $allowed).').',
                ['path' => $path],
            );
        }
    }

    private function firstFamilyToken(string $fontFamily): string
    {
        $s = trim($fontFamily);
        if ($s === '') {
            return '';
        }
        $parts = preg_split('/\s*,\s*/', $s) ?: [];

        return strtolower(trim((string) ($parts[0] ?? ''), " '\""));
    }
}
