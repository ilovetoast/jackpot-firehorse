<?php

namespace App\Services\BrandDNA;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Jobs\ProcessAssetJob;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\BrandModelVersionAsset;
use App\Models\User;
use App\Services\AssetPathGenerator;
use App\Services\AssetPublicationService;
use App\Services\TenantBucketService;
use App\Support\LogoVariantRasterProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates logo_on_dark (white silhouette) and logo_on_light (primary wash) PNGs from the
 * primary logo when model_payload flags request it. Raster sources use the original file; SVG
 * sources use the same generated large/medium thumbnail bytes as the asset grid (WebP/PNG).
 * Mirrors the Brand Guidelines Builder client-side flows in resources/js/utils/imageUtils.js.
 */
final class BrandLogoVariantAutomationService
{
    private const RASTER_MIME_PREFIXES = [
        'image/png',
        'image/jpeg',
        'image/jpg',
        'image/pjpeg',
        'image/webp',
        'image/gif',
    ];

    public function sync(Brand $brand, BrandModelVersion $version): void
    {
        $brand->loadMissing('tenant');
        $payload = $version->model_payload ?? [];
        $visual = $payload['visual'] ?? [];
        $standards = $payload['standards'] ?? [];

        $autoDark = $this->truthy($visual['auto_generate_logo_on_dark'] ?? $standards['auto_generate_logo_on_dark'] ?? false);
        $autoLight = $this->truthy($visual['auto_generate_logo_on_light'] ?? $standards['auto_generate_logo_on_light'] ?? false);

        if (! $autoDark && ! $autoLight) {
            return;
        }

        $logoAsset = $this->resolvePrimaryLogoAsset($brand, $version);
        if (! $logoAsset) {
            Log::channel('pipeline')->info('[BrandLogoVariantAutomation] No primary logo asset', [
                'brand_id' => $brand->id,
                'version_id' => $version->id,
            ]);

            return;
        }

        $logoAsset->loadMissing('currentVersion');
        $bytes = $this->resolveLogoSourceBytes($logoAsset);
        if ($bytes === null || $bytes === '') {
            Log::channel('pipeline')->warning('[BrandLogoVariantAutomation] Could not load logo bytes (original or thumbnail)', [
                'brand_id' => $brand->id,
                'asset_id' => $logoAsset->id,
                'mime_type' => $logoAsset->mime_type,
            ]);

            return;
        }

        $primaryHex = $this->resolvePrimaryHex($brand, $payload);
        $rgb = $primaryHex ? LogoVariantRasterProcessor::parseHexRgb($primaryHex) : null;

        if ($autoDark && ! $this->hasVariant($version, 'logo_on_dark')) {
            $png = LogoVariantRasterProcessor::whiteSilhouettePng($bytes);
            if ($png) {
                $this->createVariantAsset(
                    $brand,
                    $version,
                    $png,
                    $this->variantFilename($logoAsset, 'on-dark-white'),
                    'logo_on_dark',
                    'image/png'
                );
            } else {
                Log::channel('pipeline')->warning('[BrandLogoVariantAutomation] whiteSilhouettePng failed', [
                    'brand_id' => $brand->id,
                    'logo_asset_id' => $logoAsset->id,
                ]);
            }
        }

        if ($autoLight && ! $this->hasVariant($version, 'logo_on_light')) {
            if (! $rgb) {
                Log::channel('pipeline')->info('[BrandLogoVariantAutomation] No primary hex for on-light variant', [
                    'brand_id' => $brand->id,
                    'version_id' => $version->id,
                ]);
            } else {
                $png = LogoVariantRasterProcessor::primaryColorWashPng($bytes, $rgb);
                if ($png) {
                    $this->createVariantAsset(
                        $brand,
                        $version,
                        $png,
                        $this->variantFilename($logoAsset, 'on-light-primary'),
                        'logo_on_light',
                        'image/png'
                    );
                } else {
                    Log::channel('pipeline')->warning('[BrandLogoVariantAutomation] primaryColorWashPng failed', [
                        'brand_id' => $brand->id,
                        'logo_asset_id' => $logoAsset->id,
                    ]);
                }
            }
        }
    }

    /**
     * Manual generation from Identity tab (or API): same raster pipeline as DNA "automated logo variants",
     * without requiring Brand DNA → Standards toggles. Replaces existing logo_on_dark / logo_on_light DNA slots.
     *
     * @return array{ok: bool, on_dark_asset_id: ?string, on_light_asset_id: ?string, errors: list<string>}
     */
    public function generateExplicit(Brand $brand, BrandModelVersion $version, bool $onDark, bool $onLight): array
    {
        $brand->loadMissing('tenant');
        $out = [
            'ok' => false,
            'on_dark_asset_id' => null,
            'on_light_asset_id' => null,
            'errors' => [],
        ];

        if (! $onDark && ! $onLight) {
            $out['errors'][] = 'Choose at least one variant to generate.';

            return $out;
        }

        $logoAsset = $this->resolvePrimaryLogoAsset($brand, $version);
        if (! $logoAsset) {
            $out['errors'][] = 'Add a primary logo in Brand Images first.';

            return $out;
        }

        $logoAsset->loadMissing('currentVersion');
        $bytes = $this->resolveLogoSourceBytes($logoAsset);
        if ($bytes === null || $bytes === '') {
            $mime = strtolower((string) ($logoAsset->mime_type ?? ''));
            if (str_contains($mime, 'svg')) {
                // rsvg-convert path failed AND the processed thumbnail wasn't usable. This is
                // very rare -- usually rsvg-convert handles any valid SVG. Likely a malformed
                // SVG, disabled exec(), or missing rsvg binary on the host.
                $out['errors'][] = 'Could not read this SVG. Try re-uploading the logo, or upload a PNG/JPG version.';
            } else {
                $out['errors'][] = 'Could not load the primary logo file. Try re-uploading the logo.';
            }

            return $out;
        }

        $payload = $version->model_payload ?? [];
        $primaryHex = $this->resolvePrimaryHex($brand, $payload);
        $rgb = $primaryHex ? LogoVariantRasterProcessor::parseHexRgb($primaryHex) : null;

        if ($onDark) {
            $png = LogoVariantRasterProcessor::whiteSilhouettePng($bytes);
            if (! $png) {
                $out['errors'][] = 'Could not generate the on-dark logo.';
            } else {
                $asset = $this->createVariantAsset(
                    $brand,
                    $version,
                    $png,
                    $this->variantFilename($logoAsset, 'on-dark-white'),
                    'logo_on_dark',
                    'image/png'
                );
                if ($asset) {
                    $out['on_dark_asset_id'] = (string) $asset->id;
                    $brand->refresh();
                } else {
                    $out['errors'][] = 'Could not save the on-dark logo.';
                }
            }
        }

        if ($onLight) {
            if (! $rgb) {
                $out['errors'][] = 'Set a primary brand color (Brand Images → Brand Colors) to generate the primary-color logo variant.';
            } else {
                $png = LogoVariantRasterProcessor::primaryColorWashPng($bytes, $rgb);
                if (! $png) {
                    $out['errors'][] = 'Could not generate the primary-color logo variant.';
                } else {
                    $asset = $this->createVariantAsset(
                        $brand,
                        $version,
                        $png,
                        $this->variantFilename($logoAsset, 'on-light-primary'),
                        'logo_on_light',
                        'image/png'
                    );
                    if ($asset) {
                        $out['on_light_asset_id'] = (string) $asset->id;
                    } else {
                        $out['errors'][] = 'Could not save the primary-color logo variant.';
                    }
                }
            }
        }

        $out['ok'] = $out['on_dark_asset_id'] !== null || $out['on_light_asset_id'] !== null;

        return $out;
    }

    private function truthy(mixed $v): bool
    {
        if ($v === true || $v === 1 || $v === '1') {
            return true;
        }
        if ($v === false || $v === 0 || $v === '0' || $v === null || $v === '') {
            return false;
        }
        if (is_string($v)) {
            return filter_var($v, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $v;
    }

    private function hasVariant(BrandModelVersion $version, string $context): bool
    {
        return BrandModelVersionAsset::where('brand_model_version_id', $version->id)
            ->where('builder_context', $context)
            ->exists();
    }

    private function resolvePrimaryLogoAsset(Brand $brand, BrandModelVersion $version): ?Asset
    {
        $pivot = BrandModelVersionAsset::where('brand_model_version_id', $version->id)
            ->where('builder_context', 'logo_reference')
            ->first();

        $id = $pivot?->asset_id ?? $brand->logo_id;
        if (! $id) {
            return null;
        }

        return Asset::withoutTrashed()->where('brand_id', $brand->id)->find($id);
    }

    /**
     * Raster originals: S3 original. SVG: rasterize the original via rsvg-convert on demand
     * (decouples variant generation from the processed-thumbnail pipeline, which can sit in
     * `pending` indefinitely for SVGs in some tenants). Falls back to the processed
     * thumbnail when rsvg-convert isn't available or fails. Other non-raster types use the
     * existing thumbnail path.
     */
    private function resolveLogoSourceBytes(Asset $asset): ?string
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if (str_contains($mime, 'svg')) {
            $rasterized = $this->rasterizeSvgOriginal($asset);
            if ($rasterized !== null && $rasterized !== '') {
                return $rasterized;
            }

            return $this->downloadThumbnailBytesForLogoVariants($asset);
        }
        if ($this->isRasterMime($mime)) {
            return $this->downloadOriginalBytes($asset);
        }

        return $this->downloadThumbnailBytesForLogoVariants($asset);
    }

    /**
     * Download the original SVG bytes and rasterize them to PNG via rsvg-convert.
     *
     * This is the fast path for SVG logo variants: it doesn't require the processed
     * thumbnail to be ready, so users can generate on-dark / primary-color variants
     * immediately after upload. The resulting PNG is then fed through the same
     * {@see LogoVariantRasterProcessor} pipeline as raster originals.
     *
     * Returns null when: rsvg-convert isn't on PATH, the SVG can't be downloaded,
     * exec() is disabled, or rsvg-convert exits non-zero.
     */
    private function rasterizeSvgOriginal(Asset $asset, int $targetWidth = 1024): ?string
    {
        if (! function_exists('exec') || ! function_exists('escapeshellarg')) {
            return null;
        }
        $exists = @exec('command -v rsvg-convert 2>/dev/null');
        if (! is_string($exists) || trim($exists) === '') {
            return null;
        }

        $svgBytes = $this->downloadOriginalBytes($asset);
        if ($svgBytes === null || $svgBytes === '') {
            return null;
        }

        if (! preg_match('/<\s*(svg|\?xml)/i', substr($svgBytes, 0, 1024))) {
            return null;
        }

        $tmpDir = sys_get_temp_dir();
        $stem = $tmpDir.'/logo-variant-'.bin2hex(random_bytes(6));
        $svgPath = $stem.'.svg';
        $pngPath = $stem.'.png';

        try {
            if (@file_put_contents($svgPath, $svgBytes) === false) {
                return null;
            }

            $command = sprintf(
                'rsvg-convert -w %d %s -o %s 2>&1',
                max(64, min(8192, $targetWidth)),
                escapeshellarg($svgPath),
                escapeshellarg($pngPath)
            );
            $out = [];
            $exit = 1;
            @exec($command, $out, $exit);
            if ($exit !== 0 || ! is_file($pngPath)) {
                Log::channel('pipeline')->warning('[BrandLogoVariantAutomation] rsvg-convert failed for SVG logo', [
                    'asset_id' => $asset->id,
                    'exit_code' => $exit,
                    'stderr' => implode("\n", $out),
                ]);

                return null;
            }

            $png = @file_get_contents($pngPath);

            return $png !== false && $png !== '' ? $png : null;
        } finally {
            @unlink($svgPath);
            @unlink($pngPath);
        }
    }

    /**
     * Generated thumbnails (WebP/PNG) stored under metadata['thumbnails'][style]['path'].
     */
    private function downloadThumbnailBytesForLogoVariants(Asset $asset): ?string
    {
        if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
            Log::channel('pipeline')->info('[BrandLogoVariantAutomation] Thumbnail not ready for variant source', [
                'asset_id' => $asset->id,
                'thumbnail_status' => $asset->thumbnail_status?->value ?? $asset->thumbnail_status,
            ]);

            return null;
        }

        foreach (['large', 'medium', 'thumb'] as $style) {
            $path = $asset->thumbnailPathForStyle($style);
            if (! $path) {
                continue;
            }
            try {
                $bytes = Storage::disk('s3')->get($path);
                if ($bytes !== null && $bytes !== '') {
                    return $bytes;
                }
            } catch (\Throwable $e) {
                Log::channel('pipeline')->warning('[BrandLogoVariantAutomation] S3 get thumbnail failed', [
                    'asset_id' => $asset->id,
                    'style' => $style,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    private function downloadOriginalBytes(Asset $asset): ?string
    {
        $path = $asset->storage_root_path ?? $asset->currentVersion?->file_path;
        if (! $path) {
            return null;
        }

        try {
            return Storage::disk('s3')->get($path);
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning('[BrandLogoVariantAutomation] S3 get failed', [
                'asset_id' => $asset->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function isRasterMime(string $mime): bool
    {
        foreach (self::RASTER_MIME_PREFIXES as $p) {
            if (str_starts_with($mime, $p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolvePrimaryHex(Brand $brand, array $payload): ?string
    {
        $visual = $payload['visual'] ?? [];
        $bc = $payload['brand_colors'] ?? $visual['brand_colors'] ?? [];
        if (is_array($bc)) {
            $h = $bc['primary_color'] ?? $bc['primary'] ?? null;
            if (is_string($h) && trim($h) !== '') {
                return trim($h);
            }
        }

        $c = $brand->primary_color ?? null;

        return is_string($c) && trim($c) !== '' ? trim($c) : null;
    }

    private function variantFilename(Asset $logoAsset, string $suffix): string
    {
        $base = pathinfo($logoAsset->original_filename ?? 'logo', PATHINFO_FILENAME);
        $base = $this->sanitizeStem((string) $base);

        return "{$base}-{$suffix}.png";
    }

    private function sanitizeStem(string $stem): string
    {
        $stem = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $stem) ?? 'logo';
        $stem = trim($stem, '-');

        return $stem !== '' ? substr($stem, 0, 80) : 'logo';
    }

    private function createVariantAsset(
        Brand $brand,
        BrandModelVersion $version,
        string $pngBinary,
        string $filename,
        string $context,
        string $mimeType
    ): ?Asset {
        $tenant = $brand->tenant;
        if (! $tenant?->uuid) {
            Log::channel('pipeline')->warning('[BrandLogoVariantAutomation] Tenant UUID missing', [
                'brand_id' => $brand->id,
            ]);

            return null;
        }

        $size = strlen($pngBinary);

        BrandModelVersionAsset::where('brand_model_version_id', $version->id)
            ->where('builder_context', $context)
            ->delete();

        $pathGenerator = app(AssetPathGenerator::class);
        $bucketService = app(TenantBucketService::class);
        $bucket = $bucketService->resolveActiveBucketOrFail($tenant);

        $assetId = (string) Str::uuid();
        $path = $pathGenerator->generateOriginalPathForAssetId($tenant, $assetId, 1, 'png');

        $asset = Asset::forceCreate([
            'id' => $assetId,
            'tenant_id' => $brand->tenant_id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::REFERENCE,
            'title' => $context === 'logo_on_dark' ? 'Logo (on dark)' : 'Logo (on light)',
            'original_filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'storage_root_path' => $path,
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'intake_state' => 'staged',
            'builder_staged' => true,
            'builder_context' => $context,
            'source' => 'logo_variant_automation',
        ]);

        try {
            Storage::disk('s3')->put($path, $pngBinary, 'private');
        } catch (\Throwable $e) {
            Log::channel('pipeline')->error('[BrandLogoVariantAutomation] S3 put failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            $asset->delete();

            return null;
        }

        AssetVersion::create([
            'id' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => $path,
            'file_size' => $size,
            'mime_type' => $mimeType,
            'checksum' => hash('sha256', $pngBinary),
            'is_current' => true,
            'pipeline_status' => 'pending',
        ]);

        $asset->refresh();
        $asset->loadMissing('currentVersion');

        BrandModelVersionAsset::create([
            'brand_model_version_id' => $version->id,
            'asset_id' => $asset->id,
            'builder_context' => $context,
        ]);

        // Persist the generated variant to the matching brand column so the Settings UI
        // sees it immediately and display call sites pick it up without extra roundtrips.
        // Historical bug: logo_on_light previously created the asset + pivot row but never
        // set brands.logo_light_id, so generated variants appeared "lost" in the UI.
        if ($context === 'logo_on_dark') {
            $brand->update(['logo_dark_id' => $asset->id, 'logo_dark_path' => null]);
        } elseif ($context === 'logo_on_light') {
            $brand->update(['logo_light_id' => $asset->id, 'logo_light_path' => null]);
        }

        $this->finalizeStagedVariant($asset, $brand, $context);
        $this->dispatchProcessing($asset);

        return $asset->fresh();
    }

    private function finalizeStagedVariant(Asset $asset, Brand $brand, string $context): void
    {
        if (! $asset->builder_staged) {
            return;
        }

        $categorySlug = match ($context) {
            'logo_reference' => 'logos',
            'crawled_logo_variant' => 'logos',
            'logo_on_dark' => 'logos',
            'logo_on_light' => 'logos',
            'visual_reference' => 'photography',
            'guidelines_pdf' => 'reference_material',
            default => null,
        };

        $promoteToAsset = in_array($context, [
            'logo_reference',
            'crawled_logo_variant',
            'visual_reference',
            'logo_on_dark',
            'logo_on_light',
        ], true);

        DB::transaction(function () use ($asset, $brand, $categorySlug, $promoteToAsset) {
            if ($categorySlug) {
                $category = \App\Models\Category::where('brand_id', $brand->id)
                    ->where('slug', $categorySlug)
                    ->first();
                if ($category) {
                    $metadata = $asset->metadata ?? [];
                    $metadata['category_id'] = $category->id;
                    $asset->metadata = $metadata;
                }
            }

            $asset->builder_staged = false;
            $asset->intake_state = 'normal';

            if ($promoteToAsset) {
                $asset->type = AssetType::ASSET;
            }

            $asset->save();

            $actor = $this->resolvePublicationActor($brand);
            if (! $asset->isPublished() && $actor) {
                try {
                    app(AssetPublicationService::class)->publish($asset, $actor);
                } catch (\Throwable $e) {
                    Log::warning('[BrandLogoVariantAutomation] Could not publish variant asset', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Publication needs a User for published_by_id and policy checks.
     * Users belong to tenants via tenant_user — there is no users.tenant_id column.
     */
    private function resolvePublicationActor(Brand $brand): ?User
    {
        $fromAuth = auth()->user();
        if ($fromAuth instanceof User) {
            return $fromAuth;
        }

        if (! $brand->tenant_id) {
            return null;
        }

        return User::query()
            ->whereHas('tenants', fn ($q) => $q->where('tenants.id', $brand->tenant_id))
            ->orderBy('id')
            ->first();
    }

    private function dispatchProcessing(Asset $asset): void
    {
        try {
            $version = $asset->currentVersion;
            if ($version) {
                $version->update(['pipeline_status' => 'processing']);
                ProcessAssetJob::dispatch($version->id)->onQueue(config('queue.images_queue', 'images'));
            }
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning('[BrandLogoVariantAutomation] ProcessAssetJob dispatch failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
