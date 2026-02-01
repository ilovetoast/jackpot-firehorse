<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\DownloadBucketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Phase D1 — Secure Asset Downloader (Foundation)
 *
 * Session-backed download bucket API: add, remove, clear, items.
 * All asset IDs are validated against brand/collection visibility.
 */
class DownloadBucketController extends Controller
{
    public function __construct(
        protected DownloadBucketService $bucket
    ) {}

    /**
     * Add an asset to the bucket. Fails closed if user cannot view the asset.
     */
    public function add(Request $request): JsonResponse
    {
        $request->validate(['asset_id' => 'required|uuid|exists:assets,id']);

        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // D6.1 / bucket UX: always return 200 with current bucket state so the UI doesn't break.
        // Ineligible or non-viewable assets are simply not added (soft filter).
        $this->bucket->add($request->input('asset_id'));

        return response()->json([
            'items' => $this->bucket->items(),
            'count' => $this->bucket->count(),
        ]);
    }

    /**
     * Add multiple assets to the bucket (e.g. "Select all on screen").
     * Validates each ID; only visible assets are added.
     */
    public function addBatch(Request $request): JsonResponse
    {
        $request->validate([
            'asset_ids' => 'required|array',
            'asset_ids.*' => 'uuid|exists:assets,id',
        ]);

        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $this->bucket->addMany($request->input('asset_ids', []));

        return response()->json([
            'items' => $this->bucket->items(),
            'count' => $this->bucket->count(),
        ]);
    }

    /**
     * Remove an asset from the bucket.
     */
    public function remove(Asset $asset): JsonResponse
    {
        $this->bucket->remove($asset->id);

        return response()->json([
            'items' => $this->bucket->items(),
            'count' => $this->bucket->count(),
        ]);
    }

    /**
     * Clear the bucket.
     */
    public function clear(): JsonResponse
    {
        $this->bucket->clear();

        return response()->json(['items' => [], 'count' => 0]);
    }

    /**
     * Get bucket item IDs. Optionally include minimal asset info for UI (id, thumbnail, name).
     */
    public function items(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['items' => [], 'count' => 0]);
        }

        $ids = $this->bucket->visibleItems();
        if (empty($ids)) {
            return response()->json(['items' => [], 'count' => 0]);
        }

        $withDetails = $request->boolean('details', false);
        if (! $withDetails) {
            return response()->json([
                'items' => array_map(fn ($id) => ['id' => $id], $ids),
                'count' => count($ids),
            ]);
        }

        $assets = Asset::query()
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (Asset $a) => Gate::forUser($user)->allows('view', $a))
            ->values();

        $items = $assets->map(function (Asset $a) {
            $metadata = $a->metadata ?? [];
            $thumbnailStatus = $a->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                ? $a->thumbnail_status->value
                : ($a->thumbnail_status ?? 'pending');

            // Match AssetController: preview URL only when preview exists in metadata
            $previewThumbnailUrl = null;
            $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
            if (! empty($previewThumbnails) && isset($previewThumbnails['preview'])) {
                $previewThumbnailUrl = route('assets.thumbnail.preview', [
                    'asset' => $a->id,
                    'style' => 'preview',
                ]);
            }

            // Final URL only when thumbnail_status === completed (same as asset grid)
            $finalThumbnailUrl = null;
            if ($thumbnailStatus === 'completed') {
                $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                $finalThumbnailUrl = route('assets.thumbnail.final', [
                    'asset' => $a->id,
                    'style' => 'thumb',
                ]);
                if ($thumbnailVersion) {
                    $finalThumbnailUrl .= '?v=' . urlencode($thumbnailVersion);
                }
            }

            // Best available: final first, then preview (never 404 for non-completed)
            $thumbnailUrl = $finalThumbnailUrl ?? $previewThumbnailUrl;

            return [
                'id' => $a->id,
                'original_filename' => $a->original_filename,
                'thumbnail_url' => $thumbnailUrl,
                'preview_thumbnail_url' => $previewThumbnailUrl,
                'final_thumbnail_url' => $finalThumbnailUrl,
                'category_name' => $a->category?->name,
            ];
        })->all();

        return response()->json([
            'items' => $items,
            'count' => count($items),
        ]);
    }
}
