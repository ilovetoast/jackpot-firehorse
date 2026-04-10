<?php

namespace App\Services;

use App\Assets\Metadata\EmbeddedMetadataSearchTextNormalizer;
use App\Models\Asset;
use App\Models\AssetMetadataIndexEntry;
use Illuminate\Support\Str;

/**
 * Adds searchable rows to asset_metadata_index for video AI insights (tags, summary, transcript excerpt,
 * structured scene/activity/setting) without touching embedded-file index rows.
 *
 * Called after video insights complete and at the end of {@see \App\Assets\Metadata\EmbeddedMetadataIndexBuilder::rebuild}
 * so embedded rebuilds do not drop video search text.
 */
class VideoInsightsSearchIndexWriter
{
    public const NAMESPACE = 'ai.video_insights';

    public const NORMALIZED_KEY_SEARCH = 'ai.video_insights.search';

    public function __construct(
        protected EmbeddedMetadataSearchTextNormalizer $searchTextNormalizer
    ) {}

    /**
     * Remove prior video-insights index rows for the asset and insert a fresh aggregate row when insights exist.
     */
    public function syncForAsset(Asset $asset): void
    {
        AssetMetadataIndexEntry::query()
            ->where('asset_id', $asset->id)
            ->where('namespace', self::NAMESPACE)
            ->delete();

        $meta = $asset->metadata ?? [];
        $insights = $meta['ai_video_insights'] ?? null;
        if (! is_array($insights) || empty($meta['ai_video_insights_completed_at'])) {
            return;
        }

        $parts = [];
        $title = trim((string) ($asset->name ?? ''));
        $orig = trim((string) ($asset->original_filename ?? ''));
        if ($title !== '') {
            $parts[] = $title;
        }
        if ($orig !== '' && $orig !== $title) {
            $parts[] = $orig;
        }

        $tagLine = '';
        if (! empty($insights['tags']) && is_array($insights['tags'])) {
            $tagLine = implode(' ', array_map('strval', $insights['tags']));
        }
        // Tags weighted higher than summary for lexical search (summary can be long / dominate).
        if ($tagLine !== '') {
            $parts[] = $tagLine;
            $parts[] = $tagLine;
        }
        if (! empty($insights['summary']) && is_string($insights['summary'])) {
            $parts[] = $insights['summary'];
        }
        if (! empty($insights['moments']) && is_array($insights['moments'])) {
            foreach ($insights['moments'] as $m) {
                if (is_array($m) && ! empty($m['label']) && is_string($m['label'])) {
                    $parts[] = $m['label'];
                }
            }
        }
        if (! empty($insights['transcript']) && is_string($insights['transcript'])) {
            $parts[] = mb_substr($insights['transcript'], 0, 4000);
        }
        $structured = $insights['metadata'] ?? [];
        if (is_array($structured)) {
            foreach (['scene', 'activity', 'setting'] as $k) {
                if (! empty($structured[$k]) && is_string($structured[$k])) {
                    $parts[] = $structured[$k];
                }
            }
        }

        $raw = trim(implode(' ', array_filter($parts)));
        if ($raw === '') {
            return;
        }

        $normalized = $this->searchTextNormalizer->normalize($raw);
        if ($normalized === '') {
            return;
        }

        $ts = now();
        AssetMetadataIndexEntry::insert([
            [
                'id' => (string) Str::uuid(),
                'asset_id' => $asset->id,
                'namespace' => self::NAMESPACE,
                'key' => 'search',
                'normalized_key' => self::NORMALIZED_KEY_SEARCH,
                'value_type' => 'string',
                'value_string' => Str::limit($raw, 4090, ''),
                'value_number' => null,
                'value_boolean' => null,
                'value_date' => null,
                'value_datetime' => null,
                'value_json' => null,
                'search_text' => Str::limit($normalized, 65000, ''),
                'is_filterable' => false,
                'is_visible' => false,
                'source_priority' => 50,
                'created_at' => $ts,
                'updated_at' => $ts,
            ],
        ]);
    }
}
