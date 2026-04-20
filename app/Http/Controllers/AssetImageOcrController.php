<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractImageOcrJob;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Trigger manual image OCR -- queued from the Brand Intelligence drawer when
 * the scoring engine flagged {@code recommend_ocr_rerun=true}, or directly.
 *
 * Note: we do NOT probe tesseract availability here. OCR runs on the queue
 * worker tier, which may be a separate machine/container from the web tier
 * that handles this request (see docs/environments/SERVER_REQUIREMENTS.md).
 * {@see \App\Jobs\ExtractImageOcrJob} checks availability on the worker and
 * records `tesseract_unavailable` in asset.metadata.ocr if the binary is
 * missing, which the drawer surfaces to the operator.
 */
class AssetImageOcrController extends Controller
{
    public function rerun(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $tenant = app('tenant');
        if (! $tenant || $asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        $userId = $request->user()?->id ? (string) $request->user()->id : null;
        ExtractImageOcrJob::dispatch($asset->id, $userId);

        return response()->json([
            'message' => 'OCR scan queued. Results will populate the asset drawer shortly.',
        ], 202);
    }
}
