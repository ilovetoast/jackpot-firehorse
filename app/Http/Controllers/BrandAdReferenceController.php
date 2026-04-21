<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandAdReference;
use App\Services\BrandIntelligence\BrandAdReferenceHintsService;
use App\Services\BrandIntelligence\BrandAdReferenceSignalExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Brand Ad References — curated "ads we want to emulate" gallery per brand.
 *
 * The DAM handles uploads; this controller only manages the brand↔asset
 * linkage + ordering + notes. All actions are brand-scoped and require
 * `update` on the brand itself (same policy that gates ad-style editing).
 */
class BrandAdReferenceController extends Controller
{
    public function __construct(
        protected BrandAdReferenceSignalExtractor $signalExtractor,
        protected BrandAdReferenceHintsService $hintsService,
    ) {}

    /** GET /app/brands/{brand}/ad-references/hints */
    public function hints(Request $request, Brand $brand): JsonResponse
    {
        $this->authorizeBrandAccess($brand);
        return response()->json(['hints' => $this->hintsService->forBrand($brand)]);
    }

    /**
     * POST /app/brands/{brand}/ad-references/{reference}/reextract
     *
     * Re-run the extractor for a single row. Handy after the user swaps
     * out the underlying asset or when the extractor algorithm bumps its
     * version (invalidating cached signals). Returns the fresh row so the
     * UI can swap in the new signals without a full reload.
     */
    public function reextract(Request $request, Brand $brand, BrandAdReference $reference): JsonResponse
    {
        $this->authorizeBrandAccess($brand);
        if ($reference->brand_id !== $brand->id) {
            abort(404);
        }
        try {
            $this->signalExtractor->extractForReference($reference);
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning('[BrandAdReference] re-extract threw', [
                'reference_id' => $reference->id,
                'error' => $e->getMessage(),
            ]);
        }
        $reference->refresh()->load('asset');
        return response()->json(['reference' => $this->serialize($reference)]);
    }

    /** GET /app/brands/{brand}/ad-references */
    public function index(Request $request, Brand $brand): JsonResponse
    {
        $this->authorizeBrandAccess($brand);

        $rows = BrandAdReference::query()
            ->where('brand_id', $brand->id)
            ->with(['asset' => function ($q) {
                $q->select('id', 'tenant_id', 'brand_id', 'original_filename', 'mime_type', 'storage_root_path');
            }])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'references' => $rows->map(fn ($r) => $this->serialize($r))->values(),
        ]);
    }

    /** POST /app/brands/{brand}/ad-references */
    public function store(Request $request, Brand $brand): JsonResponse
    {
        $this->authorizeBrandAccess($brand);

        $validated = $request->validate([
            'asset_id' => 'required|string',
            'notes' => 'nullable|string|max:2000',
        ]);

        // Guard: the asset must belong to this tenant + brand. Without this,
        // a brand in tenant A could reference DAM assets from tenant B.
        $asset = Asset::query()
            ->where('id', $validated['asset_id'])
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->first();
        if (! $asset) {
            return response()->json(['error' => 'Asset not found in this brand.'], 404);
        }

        // UNIQUE(brand_id, asset_id) means re-adding an asset just updates
        // its notes and bumps it to the end of the list (matches user intent
        // better than a duplicate-row error).
        $ref = BrandAdReference::query()->updateOrCreate(
            ['brand_id' => $brand->id, 'asset_id' => $asset->id],
            [
                'notes' => $validated['notes'] ?? null,
                'display_order' => $this->nextOrder($brand),
            ],
        );

        // Extract visual signals inline. This adds ~1-3s to the create call
        // for the first reference of a given asset — a worthwhile trade vs.
        // queueing a job (which would mean the UI sees an empty
        // `signals` card for the first few seconds and needs a polling
        // affordance). If Imagick is missing or the asset is unreadable,
        // the extractor records a non-fatal error on the row and the
        // request still succeeds — references are additive.
        try {
            $this->signalExtractor->extractForReference($ref);
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning('[BrandAdReference] signal extraction threw', [
                'reference_id' => $ref->id,
                'error' => $e->getMessage(),
            ]);
        }

        $ref->refresh()->load('asset');

        return response()->json(['reference' => $this->serialize($ref)], 201);
    }

    /** PATCH /app/brands/{brand}/ad-references/{reference} */
    public function update(Request $request, Brand $brand, BrandAdReference $reference): JsonResponse
    {
        $this->authorizeBrandAccess($brand);
        if ($reference->brand_id !== $brand->id) {
            abort(404);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        $reference->update(['notes' => $validated['notes'] ?? null]);
        $reference->load('asset');
        return response()->json(['reference' => $this->serialize($reference)]);
    }

    /** DELETE /app/brands/{brand}/ad-references/{reference} */
    public function destroy(Request $request, Brand $brand, BrandAdReference $reference): JsonResponse
    {
        $this->authorizeBrandAccess($brand);
        if ($reference->brand_id !== $brand->id) {
            abort(404);
        }
        $reference->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * POST /app/brands/{brand}/ad-references/reorder
     *
     * Body: { order: [<ref_id>, <ref_id>, ...] }
     *
     * Reassigns `display_order` based on the client's position array. Any IDs
     * not in the array are left untouched (defensive — a client using stale
     * state shouldn't re-sort stale rows to the top).
     */
    public function reorder(Request $request, Brand $brand): JsonResponse
    {
        $this->authorizeBrandAccess($brand);

        $validated = $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'integer',
        ]);

        DB::transaction(function () use ($brand, $validated) {
            foreach ($validated['order'] as $index => $refId) {
                BrandAdReference::query()
                    ->where('brand_id', $brand->id)
                    ->where('id', $refId)
                    ->update(['display_order' => $index]);
            }
        });

        return $this->index($request, $brand);
    }

    private function authorizeBrandAccess(Brand $brand): void
    {
        $tenant = app('tenant');
        if (! $tenant || $brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);
    }

    private function nextOrder(Brand $brand): int
    {
        $max = BrandAdReference::query()
            ->where('brand_id', $brand->id)
            ->max('display_order');
        return (int) ($max ?? -1) + 1;
    }

    private function serialize(BrandAdReference $ref): array
    {
        $asset = $ref->asset;
        // Reuse the same auth'd file-streaming route used elsewhere (DAM,
        // editor bridge) so the thumbnails render without leaking S3
        // presigned URLs to the browser. Falls back to the signed-URL
        // route if the asset is missing — shouldn't happen with cascade
        // delete, but we fail soft rather than 500.
        $previewUrl = $asset
            ? route('api.editor.assets.thumbnail', ['asset' => $asset->id], true)
            : null;

        return [
            'id' => (string) $ref->id,
            'brand_id' => (string) $ref->brand_id,
            'asset_id' => $asset?->id,
            'asset' => $asset ? [
                'id' => $asset->id,
                'name' => $asset->original_filename,
                'mime_type' => $asset->mime_type,
                'preview_url' => $previewUrl,
            ] : null,
            'notes' => $ref->notes,
            'display_order' => (int) $ref->display_order,
            'signals' => $ref->signals,
            'signals_extracted_at' => $ref->signals_extracted_at?->toIso8601String(),
            'signals_extraction_error' => $ref->signals_extraction_error,
            'created_at' => $ref->created_at?->toIso8601String(),
            'updated_at' => $ref->updated_at?->toIso8601String(),
        ];
    }
}
