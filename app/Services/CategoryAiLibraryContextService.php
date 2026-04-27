<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Peer context for AI metadata vision on custom categories (opt-in).
 *
 * - Ranking is local: quality_rating, download count (withCount), then id (deterministic).
 * - Text: top peers' existing asset_tags (diverse vocabulary, not a single template).
 * - Optional: 1–2 reference thumbnails (config) passed to the vision provider as extra images.
 */
class CategoryAiLibraryContextService
{
    public function __construct(
        protected AiMetadataGenerationService $aiMetadataGeneration
    ) {}

    /**
     * @return array{enabled: bool, prompt_addendum: string, reference_image_data_urls: array<int, string>, peer_asset_ids: array<int, int>}
     */
    public function buildForAsset(Asset $asset, ?Category $category): array
    {
        $empty = [
            'enabled' => false,
            'prompt_addendum' => '',
            'reference_image_data_urls' => [],
            'peer_asset_ids' => [],
        ];

        if (! $category || ! $category->isAiLibraryReferenceContextEnabled()) {
            return $empty;
        }

        $cfg = config('ai.metadata_tagging.library_reference_context', []);
        $pool = max(1, (int) ($cfg['peer_pool'] ?? 48));
        $maxTextPeers = max(0, (int) ($cfg['max_text_peer_assets'] ?? 8));
        $maxTagsPer = max(1, (int) ($cfg['max_tags_per_peer'] ?? 8));
        $maxRefImages = max(0, (int) ($cfg['max_reference_images'] ?? 2));

        if ($maxTextPeers === 0 && $maxRefImages === 0) {
            return $empty;
        }

        $peers = $this->queryPeerAssets($asset, $category, $pool);
        if ($peers->isEmpty()) {
            return $empty;
        }

        $orderedIds = $peers->pluck('id')->all();
        $textBlock = $maxTextPeers > 0
            ? $this->buildTagTextBlock(array_slice($orderedIds, 0, $maxTextPeers), $maxTagsPer, $peers)
            : '';

        $refUrls = [];
        if ($maxRefImages > 0) {
            foreach (array_slice($orderedIds, 0, $maxRefImages) as $refId) {
                $ref = Asset::query()->find($refId);
                if (! $ref) {
                    continue;
                }
                $dataUrl = $this->aiMetadataGeneration->fetchThumbnailForVisionAnalysis($ref);
                if (is_string($dataUrl) && $dataUrl !== '') {
                    $refUrls[] = $dataUrl;
                }
            }
        }

        if ($textBlock === '' && $refUrls === []) {
            return $empty;
        }

        $addendum = $this->composePromptAddendum($textBlock, $refUrls !== []);

        return [
            'enabled' => true,
            'prompt_addendum' => $addendum,
            'reference_image_data_urls' => $refUrls,
            'peer_asset_ids' => $orderedIds,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Asset>
     */
    protected function queryPeerAssets(Asset $asset, Category $category, int $pool)
    {
        $cid = $category->id;

        $q = Asset::query()
            ->where('tenant_id', $asset->tenant_id)
            ->where('brand_id', $asset->brand_id)
            ->where('id', '!=', $asset->id)
            ->where('status', AssetStatus::VISIBLE)
            ->where('thumbnail_status', ThumbnailStatus::COMPLETED)
            ->where(function ($query) use ($cid) {
                $query->where('metadata->category_id', $cid)
                    ->orWhere('metadata->category_id', (string) $cid);
            })
            ->withCount('downloads');

        // Prefer higher user rating, then more downloads, then stable id.
        $q->orderByDesc('metadata->quality_rating')
            ->orderByDesc('downloads_count')
            ->orderByDesc('id');

        return $q->limit($pool)->get();
    }

    /**
     * @param  array<int, int>  $assetIds
     * @param  \Illuminate\Support\Collection<int, \App\Models\Asset>  $peers
     */
    protected function buildTagTextBlock(array $assetIds, int $maxTagsPer, $peers): string
    {
        if ($assetIds === []) {
            return '';
        }

        $rows = DB::table('asset_tags')
            ->whereIn('asset_id', $assetIds)
            ->orderBy('tag')
            ->get(['asset_id', 'tag']);

        $byAsset = $rows->groupBy('asset_id');
        $lines = [];

        foreach ($assetIds as $aid) {
            $group = $byAsset->get($aid);
            if (! $group || $group->isEmpty()) {
                continue;
            }
            $tags = $group->pluck('tag')->filter()->unique()->values()->take($maxTagsPer);
            if ($tags->isEmpty()) {
                continue;
            }
            $peer = $peers->firstWhere('id', $aid);
            $rating = $peer ? (int) ($peer->metadata['quality_rating'] ?? 0) : 0;
            $dc = $peer ? (int) ($peer->downloads_count ?? 0) : 0;
            $lines[] = 'rating '.$rating.', '.$dc.' download group(s): '.$tags->implode(', ');
        }

        if ($lines === []) {
            return '';
        }

        return "Peer examples from this same category (local ranking by star rating, then download usage — use as vocabulary hints only, not as a single template; do not assume this image matches a reference pose, angle, or SKU):\n"
            .implode("\n", array_map(static fn (string $l) => '- '.$l, $lines));
    }

    protected function composePromptAddendum(string $textBlock, bool $hasRefImages): string
    {
        $parts = [];
        if ($textBlock !== '') {
            $parts[] = $textBlock;
        }
        if ($hasRefImages) {
            $parts[] = 'If reference thumbnails are included in the API request, they appear before the target image. Only the last image in the request is the asset to tag. Do not copy labels from references; use them to understand the kinds of subjects and phrasing common in this custom category.';
        }

        if ($parts === []) {
            return '';
        }

        return "LIBRARY REFERENCE (custom category — anti-overfit):\n"
            .implode("\n\n", $parts)."\n\n";
    }
}
