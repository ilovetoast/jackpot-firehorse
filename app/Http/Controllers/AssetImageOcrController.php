<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractImageOcrJob;
use App\Models\Asset;
use App\Services\ImageOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Trigger manual image OCR -- queued from the Brand Intelligence drawer when
 * the scoring engine flagged {@code recommend_ocr_rerun=true}, or directly.
 */
class AssetImageOcrController extends Controller
{
    public function rerun(Request $request, Asset $asset, ImageOcrService $ocrService): JsonResponse
    {
        Gate::authorize('view', $asset);

        $tenant = app('tenant');
        if (! $tenant || $asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        if (! $ocrService->isAvailable()) {
            return response()->json([
                'message' => 'OCR is not available on this server. Ask an administrator to install tesseract-ocr on the queue worker.',
            ], 503);
        }

        $userId = $request->user()?->id ? (string) $request->user()->id : null;
        ExtractImageOcrJob::dispatch($asset->id, $userId);

        return response()->json([
            'message' => 'OCR scan queued. Results will populate the asset drawer shortly.',
        ], 202);
    }
}
