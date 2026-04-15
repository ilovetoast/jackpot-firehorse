<?php

namespace App\Services\BrandIntelligence;

use App\Models\Asset;

/**
 * Deterministic selection of up to N PDF page rasters for Brand Intelligence (no rendering).
 */
final class PdfBrandIntelligencePageSelector
{
    public const DEFAULT_MAX_PAGES = 3;

    /**
     * @param  list<array{page: int, storage_path: string, origin: string, size_bytes?: int}>  $catalogEntries
     * @return array{
     *   strategy: string,
     *   total_pdf_pages_known: int|null,
     *   selected_pages: list<int>,
     *   entries: list<array{page: int, storage_path: string, origin: string, size_bytes?: int}>
     * }
     */
    public static function select(Asset $asset, array $catalogEntries, int $maxPages = self::DEFAULT_MAX_PAGES): array
    {
        if ($catalogEntries === []) {
            return [
                'strategy' => 'none',
                'total_pdf_pages_known' => null,
                'selected_pages' => [],
                'entries' => [],
            ];
        }

        $pageMap = [];
        foreach ($catalogEntries as $e) {
            $p = (int) $e['page'];
            if ($p >= 1) {
                $pageMap[$p] = $e;
            }
        }
        $sortedPages = array_keys($pageMap);
        sort($sortedPages, SORT_NUMERIC);
        $maxPageObserved = max($sortedPages);

        $meta = is_array($asset->metadata) ? $asset->metadata : [];
        $fromColumn = (int) ($asset->pdf_page_count ?? 0);
        $fromMeta = (int) ($meta['pdf_page_count'] ?? 0);
        $totalKnown = max($fromColumn, $fromMeta, $maxPageObserved, 1);

        $contentRichPage = self::pickLargestRasterPage($pageMap);
        $multi = count($sortedPages) > 1;
        $strategy = ! $multi
            ? 'single_page_only'
            : ($contentRichPage !== null
                ? 'first_largest_raster_then_spaced_then_fill'
                : 'first_middle_last_then_fill');

        $mid = (int) max(1, (int) ceil($totalKnown / 2));
        $last = max(1, $totalKnown);

        $preferred = [1];
        if ($contentRichPage !== null && $contentRichPage !== 1) {
            $preferred[] = $contentRichPage;
        }
        $preferred[] = $mid;
        $preferred[] = $last;
        $chosen = [];
        foreach ($preferred as $p) {
            if (isset($pageMap[$p]) && ! in_array($p, $chosen, true)) {
                $chosen[] = $p;
            }
            if (count($chosen) >= $maxPages) {
                break;
            }
        }
        foreach ($sortedPages as $p) {
            if (count($chosen) >= $maxPages) {
                break;
            }
            if (! in_array($p, $chosen, true)) {
                $chosen[] = $p;
            }
        }

        $entries = [];
        foreach ($chosen as $p) {
            if (isset($pageMap[$p])) {
                $entries[] = $pageMap[$p];
            }
        }

        return [
            'strategy' => $strategy,
            'total_pdf_pages_known' => $totalKnown,
            'selected_pages' => $chosen,
            'entries' => $entries,
        ];
    }

    /**
     * Prefer the page with the largest stored raster (proxy for layout/detail richness) when {@see size_bytes} exists.
     *
     * @param  array<int, array{page: int, storage_path: string, origin: string, size_bytes?: int}>  $pageMap
     */
    protected static function pickLargestRasterPage(array $pageMap): ?int
    {
        $bestPage = null;
        $bestBytes = -1;
        foreach ($pageMap as $p => $e) {
            $bytes = (int) ($e['size_bytes'] ?? 0);
            if ($bytes < 1) {
                continue;
            }
            if ($bytes > $bestBytes || ($bytes === $bestBytes && ($bestPage === null || $p < $bestPage))) {
                $bestBytes = $bytes;
                $bestPage = $p;
            }
        }

        return $bestPage;
    }
}
