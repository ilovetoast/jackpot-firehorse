<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractPdfTextJob;
use App\Models\Asset;
use App\Models\PdfTextExtraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetPdfTextExtractionController extends Controller
{
    /**
     * Trigger manual PDF text extraction (OCR). Creates a new extraction record and dispatches the job.
     */
    public function store(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('requestFullPdfExtraction', $asset);

        $tenant = app('tenant');
        if (!$tenant || $asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        $mime = strtolower((string) ($asset->mime_type ?? ''));
        $extension = strtolower(pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));
        if (!str_contains($mime, 'pdf') && $extension !== 'pdf') {
            return response()->json(['message' => 'Asset is not a PDF.'], 422);
        }

        $asset->loadMissing('currentVersion');
        $versionId = $asset->currentVersion?->id;

        $extraction = $asset->pdfTextExtractions()->create([
            'asset_version_id' => $versionId,
            'extraction_source' => null,
            'status' => PdfTextExtraction::STATUS_PENDING,
        ]);

        ExtractPdfTextJob::dispatch(
            $asset->id,
            $extraction->id,
            $versionId
        );

        return response()->json([
            'id' => $extraction->id,
            'status' => $extraction->status,
            'message' => 'Text extraction started.',
        ], 202);
    }

    /**
     * Get the latest PDF text extraction for the asset (for modal preview / status).
     */
    public function show(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $tenant = app('tenant');
        if (!$tenant || $asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        $asset->loadMissing('currentVersion');
        $extraction = $asset->getLatestPdfTextExtractionForVersion($asset->currentVersion?->id);
        if (!$extraction) {
            return response()->json(['extraction' => null], 200);
        }

        $payload = [
            'id' => $extraction->id,
            'status' => $extraction->status,
            'extraction_source' => $extraction->extraction_source,
            'processed_at' => $extraction->processed_at?->toIso8601String(),
            'error_message' => $extraction->error_message,
            'character_count' => $extraction->character_count,
        ];

        if ($extraction->isComplete()) {
            $payload['extracted_text'] = $extraction->extracted_text;
        }

        return response()->json(['extraction' => $payload], 200);
    }
}
