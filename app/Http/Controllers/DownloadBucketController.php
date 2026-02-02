<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\DownloadBucketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase D1 — Download bucket (session-backed selection of assets for creating a download).
 * All actions require auth and tenant context; bucket is per-session.
 */
class DownloadBucketController extends Controller
{
    /**
     * GET /app/download-bucket/items
     * Returns bucket asset IDs. If ?details=1, returns items with id and brand_id for each visible asset
     * (so the UI can compute distinctBrandCount and disable brand restriction when multi-brand).
     */
    public function items(Request $request): JsonResponse
    {
        $service = app(DownloadBucketService::class);

        if ($request->boolean('details')) {
            $visibleIds = $service->visibleItems();
            if (empty($visibleIds)) {
                return response()->json(['items' => []]);
            }
            $assets = Asset::query()
                ->whereIn('id', $visibleIds)
                ->get(['id', 'brand_id', 'original_filename', 'metadata', 'thumbnail_status'])
                ->keyBy('id');
            // Preserve order of visibleIds (visibleItems() order); include thumbnail URLs for bucket bar and create-download panel.
            $items = [];
            foreach ($visibleIds as $id) {
                $asset = $assets->get($id);
                if (! $asset) {
                    continue;
                }
                $metadata = $asset->metadata ?? [];
                $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                    ? $asset->thumbnail_status->value
                    : ($asset->thumbnail_status ?? 'pending');

                $previewThumbnailUrl = null;
                $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
                if (! empty($previewThumbnails) && isset($previewThumbnails['preview'])) {
                    $previewThumbnailUrl = route('assets.thumbnail.preview', ['asset' => $asset->id, 'style' => 'preview']);
                }

                $finalThumbnailUrl = null;
                $thumbnailVersion = null;
                if ($thumbnailStatus === 'completed') {
                    $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                    $thumbnailStyle = $asset->thumbnailPathForStyle('medium') ? 'medium' : 'thumb';
                    $finalThumbnailUrl = route('assets.thumbnail.final', ['asset' => $asset->id, 'style' => $thumbnailStyle]);
                    if ($thumbnailVersion) {
                        $finalThumbnailUrl .= '?v=' . urlencode($thumbnailVersion);
                    }
                }

                $items[] = [
                    'id' => $asset->id,
                    'brand_id' => $asset->brand_id,
                    'original_filename' => $asset->original_filename,
                    'preview_thumbnail_url' => $previewThumbnailUrl,
                    'final_thumbnail_url' => $finalThumbnailUrl,
                    'thumbnail_url' => $finalThumbnailUrl ?? $previewThumbnailUrl,
                ];
            }

            return response()->json(['items' => $items]);
        }

        $ids = $service->items();

        return response()->json([
            'count' => count($ids),
            'items' => $ids,
        ]);
    }

    /**
     * POST /app/download-bucket/add
     * Body: { asset_id: string }
     * Returns 403 if asset not found or user cannot view it.
     */
    public function add(Request $request): JsonResponse
    {
        $request->validate(['asset_id' => 'required|string']);
        $service = app(DownloadBucketService::class);
        if (! $service->add($request->input('asset_id'))) {
            return response()->json(['message' => 'Asset not found or access denied.'], 403);
        }

        return response()->json([
            'count' => $service->count(),
            'items' => $service->items(),
        ]);
    }

    /**
     * POST /app/download-bucket/add-batch
     * Body: { asset_ids: string[] }
     */
    public function addBatch(Request $request): JsonResponse
    {
        $request->validate(['asset_ids' => 'required|array', 'asset_ids.*' => 'string']);
        $service = app(DownloadBucketService::class);
        $service->addMany($request->input('asset_ids', []));

        return response()->json([
            'count' => $service->count(),
            'items' => $service->items(),
        ]);
    }

    /**
     * DELETE /app/download-bucket/remove/{asset}
     */
    public function remove(Asset $asset): JsonResponse
    {
        $this->authorize('view', $asset);
        $service = app(DownloadBucketService::class);
        $service->remove($asset->id);

        return response()->json([
            'count' => $service->count(),
            'items' => $service->items(),
        ]);
    }

    /**
     * POST /app/download-bucket/clear
     */
    public function clear(): JsonResponse
    {
        $service = app(DownloadBucketService::class);
        $service->clear();

        return response()->json([
            'count' => 0,
            'items' => [],
        ]);
    }
}
