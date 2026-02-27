<?php

namespace App\Http\Controllers;

use App\Enums\ThumbnailStatus;
use App\Jobs\PdfPageRenderJob;
use App\Models\Asset;
use App\Models\AssetPdfPage;
use App\Services\AssetDeliveryService;
use App\Support\DeliveryContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class AssetPdfPageController extends Controller
{
    public function __construct(
        protected AssetDeliveryService $assetDeliveryService
    ) {
    }

    /**
     * Resolve one PDF page URL (render on-demand when missing).
     */
    public function show(Request $request, Asset $asset, int $page): JsonResponse
    {
        Gate::authorize('view', $asset);

        $tenant = app('tenant');
        if (!$tenant || $asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        $result = $this->resolvePdfPage($asset, $page);
        return response()->json($result['payload'], $result['http_status']);
    }

    /**
     * Resolve PDF page response (same logic as show). Used by drawer and by artisan pdf:test-page.
     *
     * @return array{payload: array, http_status: int}
     */
    public function resolvePdfPage(Asset $asset, int $page): array
    {
        if ($page < 1) {
            return [
                'payload' => ['message' => 'Page must be >= 1.'],
                'http_status' => 422,
            ];
        }

        $mime = strtolower((string) ($asset->mime_type ?? ''));
        $extension = strtolower(pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));
        $asset->loadMissing('currentVersion');
        $pathExtension = $asset->currentVersion?->file_path
            ? strtolower(pathinfo($asset->currentVersion->file_path, PATHINFO_EXTENSION))
            : '';
        $isPdf = str_contains($mime, 'pdf') || $extension === 'pdf' || $pathExtension === 'pdf';
        if (!$isPdf) {
            return [
                'payload' => ['message' => 'Asset is not a PDF.'],
                'http_status' => 422,
            ];
        }

        $versionNumber = $asset->currentVersion?->version_number ?? 1;
        $pageCount = (int) ($asset->pdf_page_count ?? 0);

        if ($pageCount > 0 && $page > $pageCount) {
            return [
                'payload' => [
                    'status' => 'failed',
                    'message' => "Page {$page} exceeds page count {$pageCount}.",
                    'page' => $page,
                    'page_count' => $pageCount,
                ],
                'http_status' => 422,
            ];
        }

        $record = AssetPdfPage::query()
            ->where('asset_id', $asset->id)
            ->where('version_number', $versionNumber)
            ->where('page_number', $page)
            ->first();

        // Only return "ready" with a URL when the page has been rendered (completed record).
        $isCompleted = $record && $record->status === 'completed' && !empty($record->storage_path);
        $url = $isCompleted
            ? $this->assetDeliveryService->getPdfPageUrl($asset, $page, DeliveryContext::AUTHENTICATED->value)
            : '';
        $placeholder = (string) config('assets.delivery.placeholder_url', '');
        $isReady = $isCompleted && $url !== '' && $url !== $placeholder;

        if ($isReady) {
            return [
                'payload' => [
                    'status' => 'ready',
                    'page' => $page,
                    'page_count' => $pageCount > 0 ? $pageCount : null,
                    'url' => $url,
                ],
                'http_status' => 200,
            ];
        }

        // Terminal failure: page render failed (e.g. Imagick error).
        if ($record && $record->status === 'failed') {
            return [
                'payload' => [
                    'status' => 'failed',
                    'page' => $page,
                    'page_count' => $pageCount > 0 ? $pageCount : null,
                    'message' => $record->error ?? 'PDF page could not be rendered.',
                ],
                'http_status' => 200,
            ];
        }

        // Do not dispatch on-demand when the pipeline is already running (e.g. user reprocessed with drawer open).
        // ProcessAssetJob already dispatches PdfPageRenderJob(1) for PDFs, and GenerateThumbnailsJob creates
        // AssetPdfPage for page 1 when done. Dispatching again here would duplicate work and can cause races.
        $thumbnailStatus = $asset->thumbnail_status instanceof ThumbnailStatus
            ? $asset->thumbnail_status
            : ThumbnailStatus::tryFrom((string) $asset->thumbnail_status);
        $pipelineRunning = $thumbnailStatus === ThumbnailStatus::PENDING || $thumbnailStatus === ThumbnailStatus::PROCESSING;
        if ($pipelineRunning) {
            return [
                'payload' => [
                    'status' => 'processing',
                    'page' => $page,
                    'page_count' => $pageCount > 0 ? $pageCount : null,
                    'poll_after_ms' => 1200,
                    'message' => 'Pipeline is running; page will be ready when thumbnails complete.',
                ],
                'http_status' => 202,
            ];
        }

        // Re-dispatch if no record, or not currently processing, or stuck in "processing" (e.g. job timed out).
        // Throttle: only one dispatch per asset+page per 60s to avoid storm when viewer polls every ~1.2s.
        $staleProcessingMinutes = 10;
        $shouldDispatch = !$record
            || $record->status !== 'processing'
            || ($record->updated_at && $record->updated_at->diffInMinutes(now()) >= $staleProcessingMinutes);

        $dispatchKey = 'pdf:render-dispatch:' . $asset->id . ':' . $page;
        if ($shouldDispatch && Cache::add($dispatchKey, true, 60)) {
            if ($record && $record->status === 'processing') {
                Log::info('[AssetPdfPageController] Re-dispatching stuck PDF page render', [
                    'asset_id' => $asset->id,
                    'page' => $page,
                    'stale_minutes' => $record->updated_at?->diffInMinutes(now()),
                ]);
            }
            PdfPageRenderJob::dispatch($asset->id, $page, $asset->currentVersion?->id);
        }

        return [
            'payload' => [
                'status' => 'processing',
                'page' => $page,
                'page_count' => $pageCount > 0 ? $pageCount : null,
                'poll_after_ms' => 1200,
            ],
            'http_status' => 202,
        ];
    }

    /**
     * Request full PDF extraction in background.
     */
    public function requestFullExtraction(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('requestFullPdfExtraction', $asset);

        $tenant = app('tenant');
        if (!$tenant || $asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        $requested = $asset->requestFullPdfExtraction((string) $request->user()?->id);
        if (!$requested) {
            return response()->json(['message' => 'Asset is not a PDF.'], 422);
        }

        return response()->json([
            'status' => 'queued',
            'message' => 'Full PDF extraction requested.',
            'asset_id' => $asset->id,
            'page_count' => $asset->pdf_page_count,
        ], 202);
    }
}
