<?php

namespace App\Http\Controllers;

use App\Jobs\PdfPageRenderJob;
use App\Models\Asset;
use App\Models\AssetPdfPage;
use App\Services\AssetDeliveryService;
use App\Support\DeliveryContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
        if ($page < 1) {
            return response()->json(['message' => 'Page must be >= 1.'], 422);
        }

        $mime = strtolower((string) $asset->mime_type);
        if (!str_contains($mime, 'pdf')) {
            return response()->json(['message' => 'Asset is not a PDF.'], 422);
        }

        $asset->loadMissing('currentVersion');
        $versionNumber = $asset->currentVersion?->version_number ?? 1;
        $pageCount = (int) ($asset->pdf_page_count ?? 0);

        if ($pageCount > 0 && $page > $pageCount) {
            return response()->json([
                'status' => 'failed',
                'message' => "Page {$page} exceeds page count {$pageCount}.",
                'page' => $page,
                'page_count' => $pageCount,
            ], 422);
        }

        $record = AssetPdfPage::query()
            ->where('asset_id', $asset->id)
            ->where('version_number', $versionNumber)
            ->where('page_number', $page)
            ->first();

        $url = $this->assetDeliveryService->getPdfPageUrl(
            $asset,
            $page,
            DeliveryContext::AUTHENTICATED->value
        );
        $placeholder = (string) config('assets.delivery.placeholder_url', '');
        $isReady = $url !== '' && $url !== $placeholder;

        if ($isReady) {
            return response()->json([
                'status' => 'ready',
                'page' => $page,
                'page_count' => $pageCount > 0 ? $pageCount : null,
                'url' => $url,
            ]);
        }

        if (!$record || $record->status !== 'processing') {
            PdfPageRenderJob::dispatch($asset->id, $page, $asset->currentVersion?->id);
        }

        return response()->json([
            'status' => 'processing',
            'page' => $page,
            'page_count' => $pageCount > 0 ? $pageCount : null,
            'poll_after_ms' => 1200,
        ], 202);
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
