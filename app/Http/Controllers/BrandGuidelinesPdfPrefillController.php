<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\PdfTextExtraction;
use App\Services\BrandDNA\BrandVersionService;
use App\Services\BrandDNA\GuidelinesPdfToBrandDnaMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandGuidelinesPdfPrefillController extends Controller
{
    public const CONTEXT_GUIDELINES_PDF = 'guidelines_pdf';

    public function __construct(
        private BrandVersionService $draftService,
        private GuidelinesPdfToBrandDnaMapper $mapper
    ) {}

    /**
     * Apply PDF-extracted text as prefill to the current draft.
     *
     * POST /app/brands/{brand}/brand-dna/builder/prefill-from-guidelines-pdf
     */
    public function store(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Brand not found.'], 404);
        }

        $validated = $request->validate([
            'asset_id' => 'required|string|uuid|exists:assets,id',
            'mode' => 'nullable|string|in:fill_empty,replace',
            'target_version_id' => 'nullable|integer|exists:brand_model_versions,id',
        ]);

        $assetId = $validated['asset_id'];
        $mode = $validated['mode'] ?? 'fill_empty';
        $targetVersionId = isset($validated['target_version_id']) ? (int) $validated['target_version_id'] : null;

        $asset = Asset::find($assetId);
        if (! $asset || $asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        if ($asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset must belong to this brand.'], 422);
        }

        $context = $asset->builder_context ?? '';
        if ($context !== self::CONTEXT_GUIDELINES_PDF) {
            return response()->json(['message' => 'Asset must have builder_context guidelines_pdf.'], 422);
        }

        $asset->loadMissing('currentVersion');
        $extraction = $asset->getLatestPdfTextExtractionForVersion($asset->currentVersion?->id);

        if (! $extraction) {
            return response()->json([
                'status' => 'pending',
                'message' => 'Please trigger extraction first and wait for it to complete.',
            ], 422);
        }

        if ($extraction->isPending()) {
            return response()->json([
                'status' => 'pending',
                'message' => 'Extraction is still running. Please wait.',
            ], 422);
        }

        $text = trim($extraction->extracted_text ?? '');
        if ($text === '') {
            return response()->json([
                'status' => 'empty',
                'message' => 'This PDF appears to have no selectable text (e.g. scanned image).',
            ], 422);
        }

        $suggested = $this->mapper->map($text, $asset->id);

        try {
            $result = $this->draftService->applyPrefillPatch($brand, $suggested, $mode, $targetVersionId);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'applied',
            'applied' => $result['applied'],
            'skipped' => $result['skipped'],
            'suggested' => $suggested,
        ], 200);
    }
}
