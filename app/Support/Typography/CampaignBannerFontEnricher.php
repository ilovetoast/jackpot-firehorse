<?php

namespace App\Support\Typography;

/**
 * Adds specimen / DAM download hints for campaign identity fonts passed to the UI banner.
 *
 * @see CollectionController
 */
final class CampaignBannerFontEnricher
{
    /**
     * @param  list<array<string, mixed>>  $fonts
     * @return list<array<string, mixed>>
     */
    public static function enrich(array $fonts): array
    {
        $out = [];
        foreach ($fonts as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            $row = $entry;
            $row['campaign_font_tag'] = 'Campaign font';
            $row['dam_asset_id'] = $row['dam_asset_id'] ?? null;
            $row['specimen_url'] = $row['specimen_url'] ?? null;
            $row['download_kind'] = $row['download_kind'] ?? null;

            $source = strtolower((string) ($entry['source'] ?? ''));
            $urls = $entry['file_urls'] ?? [];
            if (! is_array($urls)) {
                $urls = [];
            }

            foreach ($urls as $u) {
                if (! is_string($u) || $u === '') {
                    continue;
                }
                $assetId = self::parseAssetIdFromCampaignFontUrl($u);
                if ($assetId !== null) {
                    $row['dam_asset_id'] = $assetId;
                    $row['download_kind'] = 'dam_file';
                    break;
                }
            }

            if ($row['dam_asset_id'] === null && $source === 'google' && $name !== '') {
                $row['specimen_url'] = 'https://fonts.google.com/specimen/'.rawurlencode($name);
                $row['download_kind'] = 'google_specimen';
                $stylesheet = GoogleFontStylesheetHelper::stylesheetUrlForGoogleFontEntry($entry);
                if ($stylesheet !== null) {
                    $row['stylesheet_preview_url'] = $stylesheet;
                }
            }

            $out[] = $row;
        }

        return $out;
    }

    public static function parseAssetIdFromCampaignFontUrl(string $url): ?string
    {
        $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
        $patterns = [
            '#/assets/('.$uuid.')/(?:download|file)#',
            '#/assets/(\d+)/(?:download|file)#',
            '#/api/assets/('.$uuid.')/(?:file|download)#',
            '#/api/assets/(\d+)/(?:file|download)#',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
