<?php

namespace App\Services\BrandIntelligence;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Support\ThumbnailMetadata;

/**
 * Chooses the raster image used for Brand Intelligence vision, embeddings, and related paths
 * when the root asset is not a raw image (e.g. PDF page render stored as thumbnail).
 */
final class VisualEvaluationSourceResolver
{
    /**
     * @return array{
     *     resolved: bool,
     *     source_type: string,
     *     origin: ?string,
     *     storage_path: ?string,
     *     mime_type: ?string,
     *     page: ?int,
     *     reason: string,
     *     root_mime_type: ?string
     * }
     */
    public function resolve(Asset $asset): array
    {
        $mime = strtolower(trim((string) ($asset->mime_type ?? '')));
        $metadata = is_array($asset->metadata) ? $asset->metadata : [];

        $mediumPath = ThumbnailMetadata::stylePath($metadata, 'medium');
        $previewPath = ThumbnailMetadata::previewPath($metadata);
        $thumbPath = ThumbnailMetadata::stylePath($metadata, 'thumb');

        $pick = null;
        $origin = null;
        if ($this->isUsableRasterPath($mediumPath, $asset)) {
            $pick = $mediumPath;
            $origin = 'preferred_thumbnail';
        } elseif ($this->isUsableRasterPath($previewPath, $asset)) {
            $pick = $previewPath;
            $origin = 'enhanced_preview';
        } elseif ($this->isUsableRasterPath($thumbPath, $asset)) {
            $pick = $thumbPath;
            $origin = 'thumbnail';
        }

        if ($pick === null || ! is_string($pick)) {
            return $this->nonePayload($mime, 'no_raster_thumbnail_in_metadata');
        }

        $page = null;
        if (preg_match('/page[_-]?(\d+)/i', $pick, $m)) {
            $page = (int) $m[1];
        }

        if (str_starts_with($mime, 'image/')) {
            return [
                'resolved' => true,
                'source_type' => 'original_image',
                'origin' => $origin,
                'storage_path' => $pick,
                'mime_type' => $this->inferRasterMimeFromPath($pick),
                'page' => $page,
                'reason' => 'ok',
                'root_mime_type' => $mime !== '' ? $mime : null,
            ];
        }

        if ($mime === 'application/pdf') {
            return [
                'resolved' => true,
                'source_type' => 'pdf_rendered_image',
                'origin' => $origin,
                'storage_path' => $pick,
                'mime_type' => $this->inferRasterMimeFromPath($pick),
                'page' => $page,
                'reason' => 'ok',
                'root_mime_type' => $mime,
            ];
        }

        // Video and other non-image roots keep existing BI behavior (no creative vision via preview raster here).
        return $this->nonePayload($mime, 'non_image_root_type');
    }

    /**
     * True when an embedding / CLIP-style request should be allowed for this asset
     * even if {@see \App\Services\ImageEmbeddingService::isImageMimeType} is false on the root file.
     *
     * @param  array<string, mixed>  $resolveResult
     */
    public static function allowsImageLikeEmbedding(array $resolveResult): bool
    {
        return ($resolveResult['resolved'] ?? false) === true;
    }

    /**
     * True when any BI-usable raster exists: single-path resolve, multi-page {@see AssetPdfPage} rows, or page paths in metadata.
     */
    public function assetHasRenderableRaster(Asset $asset): bool
    {
        if (($this->resolve($asset)['resolved'] ?? false) === true) {
            return true;
        }

        return PdfBrandIntelligencePageRasterCatalog::discoverRastersByPage($asset, $this) !== [];
    }

    /**
     * Resolved PDF page raster candidates for Brand Intelligence (paths only — no I/O).
     *
     * @return list<array{
     *     page: int,
     *     source_type: string,
     *     origin: ?string,
     *     resolved: bool,
     *     reason: string,
     *     storage_path: ?string,
     *     mime_type: ?string
     * }>
     */
    public function resolvePdfVisualSources(Asset $asset, int $maxPages = 3): array
    {
        if (strtolower(trim((string) ($asset->mime_type ?? ''))) !== 'application/pdf') {
            return [];
        }

        $catalog = PdfBrandIntelligencePageRasterCatalog::discoverRastersByPage($asset, $this);
        $plan = PdfBrandIntelligencePageSelector::select($asset, $catalog, max(1, $maxPages));
        $out = [];
        foreach ($plan['entries'] as $e) {
            $path = (string) ($e['storage_path'] ?? '');
            $page = (int) ($e['page'] ?? 0);
            $origin = isset($e['origin']) && is_string($e['origin']) ? $e['origin'] : null;
            $out[] = [
                'page' => $page,
                'source_type' => 'pdf_rendered_image',
                'origin' => $origin,
                'resolved' => $path !== '',
                'reason' => $path !== '' ? 'storage_path_candidate' : 'missing_storage_path',
                'storage_path' => $path !== '' ? $path : null,
                'mime_type' => $path !== '' ? $this->inferRasterMimeFromPath($path) : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $resolveResult
     */
    public static function traceSubset(array $resolveResult): array
    {
        return [
            'used' => (bool) ($resolveResult['resolved'] ?? false),
            'source_type' => $resolveResult['source_type'] ?? 'none',
            'origin' => $resolveResult['origin'] ?? null,
            'resolved' => (bool) ($resolveResult['resolved'] ?? false),
            'reason' => $resolveResult['reason'] ?? 'unknown',
            'page' => $resolveResult['page'] ?? null,
            'root_mime_type' => $resolveResult['root_mime_type'] ?? null,
        ];
    }

    protected function isUsableRasterPath(?string $path, Asset $asset): bool
    {
        if ($path === null || $path === '') {
            return false;
        }
        if (! str_starts_with($path, 'assets/') && ! str_starts_with($path, 'temp/uploads/')) {
            return false;
        }
        if (str_starts_with($path, 'temp/uploads/')) {
            $status = $asset->thumbnail_status;

            return $status === ThumbnailStatus::COMPLETED
                || $status === ThumbnailStatus::COMPLETED->value;
        }

        return true;
    }

    protected function inferRasterMimeFromPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/webp',
        };
    }

    /**
     * @return array{resolved: bool, source_type: string, origin: null, storage_path: null, mime_type: null, page: null, reason: string, root_mime_type: ?string}
     */
    protected function nonePayload(string $mime, string $reason): array
    {
        return [
            'resolved' => false,
            'source_type' => 'none',
            'origin' => null,
            'storage_path' => null,
            'mime_type' => null,
            'page' => null,
            'reason' => $reason,
            'root_mime_type' => $mime !== '' ? $mime : null,
        ];
    }
}
