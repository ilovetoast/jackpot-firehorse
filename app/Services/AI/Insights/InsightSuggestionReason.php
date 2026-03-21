<?php

namespace App\Services\AI\Insights;

/**
 * Human-readable explanations for Insights Review (trust + clarity).
 */
class InsightSuggestionReason
{
    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function forValueSuggestion($row): string
    {
        $n = (int) (is_array($row) ? ($row['supporting_asset_count'] ?? 0) : $row->supporting_asset_count);
        $conf = is_array($row) ? ($row['confidence'] ?? null) : $row->confidence;
        $cons = is_array($row) ? ($row['consistency_score'] ?? null) : ($row->consistency_score ?? null);

        $parts = [
            sprintf('Appears in %d %s across merged tag, metadata, and candidate signals', $n, $n === 1 ? 'asset' : 'assets'),
        ];
        if ($conf !== null && $conf !== '') {
            $parts[] = sprintf('%.0f%% weighted confidence', (float) $conf * 100);
        }
        if ($cons !== null && $cons !== '') {
            $parts[] = sprintf(
                'spread across %.0f%% of distinct upload batches in this category (vs total batches)',
                (float) $cons * 100
            );
        }

        return implode(' · ', $parts).'.';
    }

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function forFieldSuggestion($row, int $categoryAssetTotal, ?string $categoryName = null): string
    {
        $supporting = (int) (is_array($row) ? ($row['supporting_asset_count'] ?? 0) : $row->supporting_asset_count);
        $cluster = (string) (is_array($row) ? ($row['source_cluster'] ?? '') : $row->source_cluster);
        $conf = is_array($row) ? ($row['confidence'] ?? null) : $row->confidence;
        $cons = is_array($row) ? ($row['consistency_score'] ?? null) : ($row->consistency_score ?? null);

        $den = max($categoryAssetTotal, 1);
        $pct = round(($supporting / $den) * 100);

        $cat = $categoryName ? sprintf(' in %s', $categoryName) : '';
        $parts = [
            sprintf('Anchor tag "%s" appears on ~%d%% of assets%s (%d assets)', $cluster, $pct, $cat, $supporting),
        ];
        if ($conf !== null && $conf !== '') {
            $parts[] = sprintf('%.0f%% model confidence', (float) $conf * 100);
        }
        if ($cons !== null && $cons !== '') {
            $parts[] = sprintf('%.0f%% batch consistency', (float) $cons * 100);
        }

        return implode(' · ', $parts).'.';
    }
}
