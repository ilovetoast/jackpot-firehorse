<?php

namespace App\Http\Controllers;

use App\Jobs\PdfPageRenderJob;
use App\Models\Asset;
use App\Services\PdfPageRenderingService;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class PdfPageController extends Controller
{
    /**
     * GET /assets/{asset}/pdf-page/{page}
     */
    public function show(Request $request, Asset $asset, int $page, PdfPageRenderingService $pdfService): JsonResponse
    {
        Gate::authorize('view', $asset);

        $tenant = app('tenant');
        $brand = app('brand');

        if ($asset->tenant_id !== $tenant->id || ($brand && $asset->brand_id !== $brand->id)) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        if ($page < 1) {
            return response()->json(['message' => 'Page must be >= 1'], 422);
        }

        if (! $pdfService->isPdfAsset($asset)) {
            return response()->json(['message' => 'Asset is not a PDF'], 422);
        }

        $pageCount = $pdfService->getPdfPageCount($asset);
        if ($pageCount < 1) {
            return response()->json(['message' => 'PDF page count unavailable'], 422);
        }

        $maxAllowedPages = (int) config('pdf.max_allowed_pages', 500);
        if ($asset->pdf_unsupported_large || $pageCount > $maxAllowedPages) {
            return response()->json([
                'status' => 'unsupported_large',
                'message' => "PDF exceeds allowed page limit ({$maxAllowedPages}).",
                'page_count' => $pageCount,
            ], 422);
        }

        if ($page > $pageCount) {
            return response()->json([
                'message' => "Requested page {$page} exceeds PDF page count {$pageCount}",
                'page_count' => $pageCount,
            ], 422);
        }

        if ($pdfService->pageExists($asset, $page)) {
            $url = $asset->deliveryUrl(
                AssetVariant::PDF_PAGE,
                DeliveryContext::AUTHENTICATED,
                [
                    'page' => $page,
                    'signed' => true,
                    'pdf_page_access' => 'admin',
                ]
            );

            return response()->json([
                'status' => 'ready',
                'url' => $url,
                'page' => $page,
                'page_count' => $pageCount,
            ]);
        }

        $dispatchKey = "pdf:render-dispatch:{$asset->id}:{$page}";
        if (Cache::add($dispatchKey, true, 20)) {
            PdfPageRenderJob::dispatch($asset->id, $page);
        }

        return response()->json([
            'status' => 'processing',
            'page' => $page,
            'page_count' => $pageCount,
        ]);
    }

    /**
     * POST /assets/{asset}/pdf/extract-all
     */
    public function extractAll(Request $request, Asset $asset, PdfPageRenderingService $pdfService): JsonResponse
    {
        Gate::authorize('view', $asset);

        $tenant = app('tenant');
        $brand = app('brand');

        if ($asset->tenant_id !== $tenant->id || ($brand && $asset->brand_id !== $brand->id)) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        if (! $pdfService->isPdfAsset($asset)) {
            return response()->json(['message' => 'Asset is not a PDF'], 422);
        }

        $user = $request->user();
        $tenantRole = strtolower((string) ($user?->getRoleForTenant($tenant) ?? ''));
        $siteRoles = method_exists($user, 'getSiteRoles') ? $user->getSiteRoles() : [];

        $isAdminOverride = in_array($tenantRole, ['owner', 'admin'], true)
            || count(array_intersect($siteRoles, ['site_owner', 'site_admin', 'site_support', 'site_engineering'])) > 0;

        if (! $isAdminOverride) {
            return response()->json([
                'message' => 'Only tenant admins/owners can run full PDF extraction.',
            ], 403);
        }

        try {
            $batch = $asset->requestFullPdfExtraction($isAdminOverride);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        if (! $batch) {
            return response()->json([
                'message' => 'Unable to start PDF extraction batch.',
            ], 422);
        }

        return response()->json([
            'status' => 'started',
            'batch_id' => $batch->id,
            'total_jobs' => $batch->totalJobs,
            'pending_jobs' => $batch->pendingJobs,
        ], 202);
    }
}
