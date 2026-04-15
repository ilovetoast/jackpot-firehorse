<?php

namespace App\Services\BrandIntelligence;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetPdfPage;
use App\Support\ThumbnailMetadata;
use Illuminate\Support\Facades\Schema;

/**
 * Discovers rendered PDF page rasters already stored (DB and/or thumbnail metadata) — no rendering here.
 */
final class PdfBrandIntelligencePageRasterCatalog
{
    /**
     * @return list<array{page: int, storage_path: string, origin: string, size_bytes?: int}>
     */
    public static function discoverRastersByPage(Asset $asset, ?VisualEvaluationSourceResolver $resolver = null): array
    {
        $mime = strtolower(trim((string) ($asset->mime_type ?? '')));
        if ($mime !== 'application/pdf') {
            return [];
        }

        $byPage = [];

        if (Schema::hasTable('asset_pdf_pages')) {
            foreach (AssetPdfPage::query()
                ->where('asset_id', $asset->id)
                ->where('status', 'completed')
                ->whereNotNull('storage_path')
                ->orderBy('page_number')
                ->get(['page_number', 'storage_path', 'size_bytes']) as $row) {
                $p = (int) $row->page_number;
                $path = (string) $row->storage_path;
                if ($p < 1 || ! self::isUsableStoragePath($path, $asset)) {
                    continue;
                }
                $sb = (int) ($row->size_bytes ?? 0);
                $entry = ['page' => $p, 'storage_path' => $path, 'origin' => 'asset_pdf_pages'];
                if ($sb > 0) {
                    $entry['size_bytes'] = $sb;
                }
                $byPage[$p] = $entry;
            }
        }

        if ($byPage === []) {
            $meta = is_array($asset->metadata) ? $asset->metadata : [];
            foreach (ThumbnailMetadata::allThumbnailObjectPaths($meta) as $path) {
                if (! preg_match('/page[_-]?(\d+)/i', $path, $m)) {
                    continue;
                }
                $p = (int) $m[1];
                if ($p < 1 || ! self::isUsableStoragePath($path, $asset)) {
                    continue;
                }
                if (! isset($byPage[$p])) {
                    $byPage[$p] = ['page' => $p, 'storage_path' => $path, 'origin' => 'thumbnail_metadata_path'];
                }
            }
        }

        if ($byPage === []) {
            $resolver ??= app(VisualEvaluationSourceResolver::class);
            $r = $resolver->resolve($asset);
            if (($r['resolved'] ?? false) === true && is_string($r['storage_path'] ?? null) && $r['storage_path'] !== '') {
                $path = $r['storage_path'];
                if (self::isUsableStoragePath($path, $asset)) {
                    $p = is_int($r['page'] ?? null) ? (int) $r['page'] : 1;
                    $p = max(1, $p);
                    $byPage[$p] = [
                        'page' => $p,
                        'storage_path' => $path,
                        'origin' => (string) ($r['origin'] ?? 'resolver_single'),
                    ];
                }
            }
        }

        ksort($byPage, SORT_NUMERIC);

        return array_values($byPage);
    }

    public static function isUsableStoragePath(string $path, Asset $asset): bool
    {
        if ($path === '') {
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
}
